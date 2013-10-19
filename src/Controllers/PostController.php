<?php
class PostController
{
	public function workWrapper($callback)
	{
		$this->context->stylesheets []= '../lib/tagit/jquery.tagit.css';
		$this->context->scripts []= '../lib/tagit/jquery.tagit.js';
		$callback();
	}

	private static function serializeTags($post)
	{
		$x = [];
		foreach ($post->sharedTag as $tag)
			$x []= $tag->name;
		natcasesort($x);
		$x = join('', $x);
		return md5($x);
	}

	private static function handleUploadErrors($file)
	{
		switch ($file['error'])
		{
			case UPLOAD_ERR_OK:
				break;
			case UPLOAD_ERR_INI_SIZE:
				throw new SimpleException('File is too big (maximum size allowed: ' . ini_get('upload_max_filesize') . ')');
			case UPLOAD_ERR_FORM_SIZE:
				throw new SimpleException('File is too big than it was allowed in HTML form');
			case UPLOAD_ERR_PARTIAL:
				throw new SimpleException('File transfer was interrupted');
			case UPLOAD_ERR_NO_FILE:
				throw new SimpleException('No file was uploaded');
			case UPLOAD_ERR_NO_TMP_DIR:
				throw new SimpleException('Server misconfiguration error: missing temporary folder');
			case UPLOAD_ERR_CANT_WRITE:
				throw new SimpleException('Server misconfiguration error: cannot write to disk');
			case UPLOAD_ERR_EXTENSION:
				throw new SimpleException('Server misconfiguration error: upload was canceled by an extension');
			default:
				throw new SimpleException('Generic file upload error (id: ' . $file['error'] . ')');
		}
		if (!is_uploaded_file($file['tmp_name']))
			throw new SimpleException('Generic file upload error');
	}



	/**
	* @route /posts
	* @route /posts/{page}
	* @route /posts/{query}/
	* @route /posts/{query}/{page}
	* @validate page \d*
	* @validate query [^\/]*
	*/
	public function listAction($query = null, $page = 1)
	{
		$this->context->stylesheets []= 'post-small.css';
		$this->context->stylesheets []= 'post-list.css';
		$this->context->stylesheets []= 'paginator.css';
		if ($this->config->browsing->endlessScrolling)
			$this->context->scripts []= 'paginator-endless.js';

		//redirect requests in form of /posts/?query=... to canonical address
		$formQuery = InputHelper::get('query');
		if ($formQuery !== null)
		{
			$this->context->transport->searchQuery = $formQuery;
			if (strpos($formQuery, '/') !== false)
				throw new SimpleException('Search query contains invalid characters');
			$url = \Chibi\UrlHelper::route('post', 'list', ['query' => urlencode($formQuery)]);
			\Chibi\UrlHelper::forward($url);
			return;
		}

		$query = trim(urldecode($query));
		$page = intval($page);
		$postsPerPage = intval($this->config->browsing->postsPerPage);
		$this->context->subTitle = 'browsing posts';
		$this->context->transport->searchQuery = $query;
		PrivilegesHelper::confirmWithException(Privilege::ListPosts);

		$buildDbQuery = function($dbQuery, $query)
		{
			$dbQuery->from('post');


			/* safety */
			$allowedSafety = array_filter(PostSafety::getAll(), function($safety)
			{
				return PrivilegesHelper::confirm(Privilege::ListPosts, PostSafety::toString($safety)) and
					$this->context->user->hasEnabledSafety($safety);
			});
			$dbQuery->where('safety IN (' . R::genSlots($allowedSafety) . ')');
			foreach ($allowedSafety as $s)
				$dbQuery->put($s);


			/* hidden */
			if (!PrivilegesHelper::confirm(Privilege::ListPosts, 'hidden'))
				$dbQuery->andNot('hidden');


			/* query tokens */
			$tokens = array_filter(array_unique(explode(' ', $query)), function($x) { return $x != ''; });
			if (count($tokens) > $this->config->browsing->maxSearchTokens)
				throw new SimpleException('Too many search tokens (maximum: ' . $this->config->browsing->maxSearchTokens . ')');


			/* tokens */
			$this->decorateSearchQuery($dbQuery, $tokens);
		};

		$countDbQuery = R::$f->begin();
		$countDbQuery->select('COUNT(1)')->as('count');
		$buildDbQuery($countDbQuery, $query);
		$postCount = intval($countDbQuery->get('row')['count']);
		$pageCount = ceil($postCount / $postsPerPage);
		$page = max(1, min($pageCount, $page));

		$searchDbQuery = R::$f->begin();
		$searchDbQuery->select('*');
		$buildDbQuery($searchDbQuery, $query);
		$searchDbQuery->limit('?')->put($postsPerPage);
		$searchDbQuery->offset('?')->put(($page - 1) * $postsPerPage);

		$posts = $searchDbQuery->get();
		$this->context->transport->paginator = new StdClass;
		$this->context->transport->paginator->page = $page;
		$this->context->transport->paginator->pageCount = $pageCount;
		$this->context->transport->paginator->entityCount = $postCount;
		$this->context->transport->paginator->entities = $posts;
		$this->context->transport->posts = $posts;
	}



	/**
	* @route /favorites
	* @route /favorites/{page}
	* @validate page \d*
	*/
	public function favoritesAction($page = 1)
	{
		$this->listAction('favmin:1', $page);
		$this->context->viewName = 'post-list';
	}



	/**
	* @route /post/upload
	*/
	public function uploadAction()
	{
		$this->context->stylesheets []= 'upload.css';
		$this->context->scripts []= 'upload.js';
		$this->context->subTitle = 'upload';
		PrivilegesHelper::confirmWithException(Privilege::UploadPost);
		if ($this->config->registration->needEmailForUploading)
			PrivilegesHelper::confirmEmail($this->context->user);

		if (!empty($_FILES['file']['name']))
		{
			/* file contents */
			$suppliedFile = $_FILES['file'];
			self::handleUploadErrors($suppliedFile);


			/* file details */
			$mimeType = mime_content_type($suppliedFile['tmp_name']);
			$imageWidth = null;
			$imageHeight = null;
			switch ($mimeType)
			{
				case 'image/gif':
				case 'image/png':
				case 'image/jpeg':
					$postType = PostType::Image;
					list ($imageWidth, $imageHeight) = getimagesize($suppliedFile['tmp_name']);
					break;
				case 'application/x-shockwave-flash':
					$postType = PostType::Flash;
					list ($imageWidth, $imageHeight) = getimagesize($suppliedFile['tmp_name']);
					break;
				default:
					throw new SimpleException('Invalid file type "' . $mimeType . '"');
			}

			$fileHash = md5_file($suppliedFile['tmp_name']);
			$duplicatedPost = R::findOne('post', 'file_hash = ?', [$fileHash]);
			if ($duplicatedPost !== null)
				throw new SimpleException('Duplicate upload: @' . $duplicatedPost->id);

			do
			{
				$name = md5(mt_rand() . uniqid());
				$path = $this->config->main->filesPath . DS . $name;
			}
			while (file_exists($path));


			/* safety */
			$suppliedSafety = InputHelper::get('safety');
			$suppliedSafety = Model_Post::validateSafety($suppliedSafety);


			/* tags */
			$suppliedTags = InputHelper::get('tags');
			$suppliedTags = Model_Post::validateTags($suppliedTags);
			$dbTags = Model_Tag::insertOrUpdate($suppliedTags);

			/* db storage */
			$dbPost = R::dispense('post');
			$dbPost->type = $postType;
			$dbPost->name = $name;
			$dbPost->orig_name = basename($suppliedFile['name']);
			$dbPost->file_hash = $fileHash;
			$dbPost->file_size = filesize($suppliedFile['tmp_name']);
			$dbPost->mime_type = $mimeType;
			$dbPost->safety = $suppliedSafety;
			$dbPost->hidden = false;
			$dbPost->upload_date = time();
			$dbPost->image_width = $imageWidth;
			$dbPost->image_height = $imageHeight;
			$dbPost->uploader = $this->context->user;
			$dbPost->ownFavoritee = [];
			$dbPost->sharedTag = $dbTags;

			move_uploaded_file($suppliedFile['tmp_name'], $path);
			R::store($dbPost);

			$this->context->transport->success = true;
		}
	}



	/**
	* @route /post/{id}/edit
	*/
	public function editAction($id)
	{
		$post = Model_Post::locate($id);
		R::preload($post, ['uploader' => 'user']);
		$edited = false;

		$this->context->transport->post = $post;

		/* safety */
		$suppliedSafety = InputHelper::get('safety');
		if ($suppliedSafety !== null)
		{
			PrivilegesHelper::confirmWithException(Privilege::EditPostSafety, PrivilegesHelper::getIdentitySubPrivilege($post->uploader));
			$suppliedSafety = Model_Post::validateSafety($suppliedSafety);
			$post->safety = $suppliedSafety;
			$edited = true;
		}


		/* tags */
		$suppliedTags = InputHelper::get('tags');
		if ($suppliedTags !== null)
		{
			PrivilegesHelper::confirmWithException(Privilege::EditPostTags, PrivilegesHelper::getIdentitySubPrivilege($post->uploader));
			$currentToken = self::serializeTags($post);
			if (InputHelper::get('tags-token') != $currentToken)
				throw new SimpleException('Someone else has changed the tags in the meantime');

			$suppliedTags = Model_Post::validateTags($suppliedTags);
			$dbTags = Model_Tag::insertOrUpdate($suppliedTags);
			$post->sharedTag = $dbTags;
			$edited = true;
		}


		/* thumbnail */
		if (!empty($_FILES['thumb']['name']))
		{
			PrivilegesHelper::confirmWithException(Privilege::EditPostThumb, PrivilegesHelper::getIdentitySubPrivilege($post->uploader));
			$suppliedFile = $_FILES['thumb'];
			self::handleUploadErrors($suppliedFile);

			$mimeType = mime_content_type($suppliedFile['tmp_name']);
			if (!in_array($mimeType, ['image/gif', 'image/png', 'image/jpeg']))
				throw new SimpleException('Invalid thumbnail type "' . $mimeType . '"');
			list ($imageWidth, $imageHeight) = getimagesize($suppliedFile['tmp_name']);
			if ($imageWidth != $this->config->browsing->thumbWidth)
				throw new SimpleException('Invalid thumbnail width (should be ' . $this->config->browsing->thumbWidth . ')');
			if ($imageWidth != $this->config->browsing->thumbHeight)
				throw new SimpleException('Invalid thumbnail width (should be ' . $this->config->browsing->thumbHeight . ')');

			$path = $this->config->main->thumbsPath . DS . $post->name;
			move_uploaded_file($suppliedFile['tmp_name'], $path);
		}


		/* db storage */
		if ($edited)
			R::store($post);
		$this->context->transport->success = true;
	}



	/**
	* @route /post/{id}/hide
	*/
	public function hideAction($id)
	{
		$post = Model_Post::locate($id);
		PrivilegesHelper::confirmWithException(Privilege::HidePost, PrivilegesHelper::getIdentitySubPrivilege($post->uploader));
		$post->hidden = true;
		R::store($post);
		$this->context->transport->success = true;
	}

	/**
	* @route /post/{id}/unhide
	*/
	public function unhideAction($id)
	{
		$post = Model_Post::locate($id);
		PrivilegesHelper::confirmWithException(Privilege::HidePost, PrivilegesHelper::getIdentitySubPrivilege($post->uploader));
		$post->hidden = false;
		R::store($post);
		$this->context->transport->success = true;
	}

	/**
	* @route /post/{id}/delete
	*/
	public function deleteAction($id)
	{
		$post = Model_Post::locate($id);
		PrivilegesHelper::confirmWithException(Privilege::DeletePost, PrivilegesHelper::getIdentitySubPrivilege($post->uploader));
		//remove stuff from auxiliary tables
		$post->ownFavoritee = [];
		$post->sharedTag = [];
		R::store($post);
		R::trash($post);
		$this->context->transport->success = true;
	}



	/**
	* @route /post/{id}/add-fav
	* @route /post/{id}/fav-add
	*/
	public function addFavoriteAction($id)
	{
		$post = Model_Post::locate($id);
		R::preload($post, ['favoritee' => 'user']);

		if (!$this->context->loggedIn)
			throw new SimpleException('Not logged in');

		foreach ($post->via('favoritee')->sharedUser as $fav)
			if ($fav->id == $this->context->user->id)
				throw new SimpleException('Already in favorites');

		PrivilegesHelper::confirmWithException(Privilege::FavoritePost);
		$post->link('favoritee')->user = $this->context->user;
		R::store($post);
		$this->context->transport->success = true;
	}

	/**
	* @route /post/{id}/rem-fav
	* @route /post/{id}/fav-rem
	*/
	public function remFavoriteAction($id)
	{
		$post = Model_Post::locate($id);
		R::preload($post, ['favoritee' => 'user']);

		PrivilegesHelper::confirmWithException(Privilege::FavoritePost);
		if (!$this->context->loggedIn)
			throw new SimpleException('Not logged in');

		$finalKey = null;
		foreach ($post->ownFavoritee as $key => $fav)
			if ($fav->user->id == $this->context->user->id)
				$finalKey = $key;

		if ($finalKey === null)
			throw new SimpleException('Not in favorites');

		unset ($post->ownFavoritee[$finalKey]);
		R::store($post);
		$this->context->transport->success = true;
	}



	/**
	* @route /post/{id}/feature
	*/
	public function featureAction($id)
	{
		$post = Model_Post::locate($id);
		PrivilegesHelper::confirmWithException(Privilege::FeaturePost);
		Model_Property::set(Model_Property::FeaturedPostId, $post->id);
		Model_Property::set(Model_Property::FeaturedPostUserId, $this->context->user->id);
		Model_Property::set(Model_Property::FeaturedPostDate, time());
		$this->context->transport->success = true;
	}



	/**
	* Action that decorates the page containing the post.
	* @route /post/{id}
	*/
	public function viewAction($id)
	{
		$post = Model_Post::locate($id);
		R::preload($post, [
			'favoritee' => 'user',
			'uploader' => 'user',
			'tag',
			'comment',
			'ownComment.commenter' => 'user']);

		if ($post->hidden)
			PrivilegesHelper::confirmWithException(Privilege::ViewPost, 'hidden');
		PrivilegesHelper::confirmWithException(Privilege::ViewPost);
		PrivilegesHelper::confirmWithException(Privilege::ViewPost, PostSafety::toString($post->safety));

		$buildNextPostQuery = function($dbQuery, $id, $next)
		{
			$dbQuery->select('id')
				->from('post')
				->where($next ? 'id > ?' : 'id < ?')
				->put($id);
			if (!PrivilegesHelper::confirm(Privilege::ListPosts, 'hidden'))
				$dbQuery->andNot('hidden');
			$dbQuery->orderBy($next ? 'id asc' : 'id desc')
				->limit(1);
		};

		$prevPostQuery = R::$f->begin();
		$buildNextPostQuery($prevPostQuery, $id, false);
		$prevPost = $prevPostQuery->get('row');

		$nextPostQuery = R::$f->begin();
		$buildNextPostQuery($nextPostQuery, $id, true);
		$nextPost = $nextPostQuery->get('row');

		$favorite = false;
		if ($this->context->loggedIn)
			foreach ($post->ownFavoritee as $fav)
				if ($fav->user->id == $this->context->user->id)
					$favorite = true;

		$dbQuery = R::$f->begin();
		$dbQuery->select('tag.name, COUNT(1) AS count');
		$dbQuery->from('tag');
		$dbQuery->innerJoin('post_tag');
		$dbQuery->on('tag.id = post_tag.tag_id');
		$dbQuery->where('tag.id IN (' . R::genSlots($post->sharedTag) . ')');
		foreach ($post->sharedTag as $tag)
			$dbQuery->put($tag->id);
		$dbQuery->groupBy('tag.id');
		$rows = $dbQuery->get();
		$this->context->transport->tagDistribution = [];
		foreach ($rows as $row)
			$this->context->transport->tagDistribution[$row['name']] = $row['count'];

		$this->context->stylesheets []= 'post-view.css';
		$this->context->stylesheets []= 'comment-small.css';
		$this->context->scripts []= 'post-view.js';
		$this->context->subTitle = 'showing @' . $post->id;
		$this->context->favorite = $favorite;
		$this->context->transport->post = $post;
		$this->context->transport->prevPostId = $prevPost ? $prevPost['id'] : null;
		$this->context->transport->nextPostId = $nextPost ? $nextPost['id'] : null;
		$this->context->transport->tagsToken = self::serializeTags($post);
	}



	/**
	* Action that renders the thumbnail of the requested file and sends it to user.
	* @route /post/{id}/thumb
	*/
	public function thumbAction($id)
	{
		$this->context->layoutName = 'layout-file';
		$post = Model_Post::locate($id);

		PrivilegesHelper::confirmWithException(Privilege::ViewPost);
		PrivilegesHelper::confirmWithException(Privilege::ViewPost, PostSafety::toString($post->safety));

		$path = $this->config->main->thumbsPath . DS . $post->name;
		if (!file_exists($path))
		{
			$srcPath = $this->config->main->filesPath . DS . $post->name;
			$dstPath = $path;
			$dstWidth = $this->config->browsing->thumbWidth;
			$dstHeight = $this->config->browsing->thumbHeight;

			switch ($post->mime_type)
			{
				case 'image/jpeg':
					$srcImage = imagecreatefromjpeg($srcPath);
					break;
				case 'image/png':
					$srcImage = imagecreatefrompng($srcPath);
					break;
				case 'image/gif':
					$srcImage = imagecreatefromgif($srcPath);
					break;
				case 'application/x-shockwave-flash':
					$path = $this->config->main->mediaPath . DS . 'img' . DS . 'thumb-swf.png';
					break;
				default:
					$path = $this->config->main->mediaPath . DS . 'img' . DS . 'thumb.png';
					break;
			}

			if (isset($srcImage))
			{
				switch ($this->config->browsing->thumbStyle)
				{
					case 'outside':
						$dstImage = ThumbnailHelper::cropOutside($srcImage, $dstWidth, $dstHeight);
						break;
					case 'inside':
						$dstImage = ThumbnailHelper::cropInside($srcImage, $dstWidth, $dstHeight);
						break;
					default:
						throw new SimpleException('Unknown thumbnail crop style');
				}

				imagepng($dstImage, $dstPath);
				imagedestroy($srcImage);
				imagedestroy($dstImage);
			}
		}
		if (!is_readable($path))
			throw new SimpleException('Thumbnail file is not readable');

		$this->context->transport->cacheDaysToLive = 30;
		$this->context->transport->mimeType = 'image/png';
		$this->context->transport->fileHash = 'thumb' . $post->file_hash;
		$this->context->transport->filePath = $path;
	}



	/**
	* Action that renders the requested file itself and sends it to user.
	* @route /post/{name}/retrieve
	*/
	public function retrieveAction($name)
	{
		$this->context->layoutName = 'layout-file';
		$post = Model_Post::locate($name, true);
		R::preload($post, ['tag']);

		PrivilegesHelper::confirmWithException(Privilege::RetrievePost);
		PrivilegesHelper::confirmWithException(Privilege::RetrievePost, PostSafety::toString($post->safety));

		$path = $this->config->main->filesPath . DS . $post->name;
		if (!file_exists($path))
			throw new SimpleException('Post file does not exist');
		if (!is_readable($path))
			throw new SimpleException('Post file is not readable');

		$ext = substr($post->orig_name, strrpos($post->orig_name, '.') + 1);
		if (strpos($post->orig_name, '.') === false)
			$ext = '.dat';
		$fn = sprintf('%s_%s_%s.%s',
			$this->config->main->title,
			$post->id, join(',', array_map(function($tag) { return $tag->name; }, $post->sharedTag)),
			$ext);
		$fn = preg_replace('/[[:^print:]]/', '', $fn);

		$ttl = 60 * 60 * 24 * 14;

		$this->context->transport->cacheDaysToLive = 14;
		$this->context->transport->customFileName = $fn;
		$this->context->transport->mimeType = $post->mimeType;
		$this->context->transport->fileHash = 'post' . $post->file_hash;
		$this->context->transport->filePath = $path;
	}



	private function decorateSearchQuery($dbQuery, $tokens)
	{
		$orderColumn = 'id';
		$orderDir = 1;
		$randomReset = true;

		foreach ($tokens as $token)
		{
			if ($token{0} == '-')
			{
				$dbQuery->andNot();
				$token = substr($token, 1);
				$neg = true;
			}
			else
			{
				$dbQuery->and();
				$neg = false;
			}

			$pos = strpos($token, ':');
			if ($pos === false)
			{
				$val = $token;
				$dbQuery
					->exists()
					->open()
					->select('1')
					->from('post_tag')
					->innerJoin('tag')
					->on('post_tag.tag_id = tag.id')
					->where('post_id = post.id')
					->and('tag.name = ?')->put($val)
					->close();
				continue;
			}

			$key = substr($token, 0, $pos);
			$val = substr($token, $pos + 1);

			switch ($key)
			{
				case 'favmin':
				case 'favmax':
					$operator = $key == 'favmin' ? '>=' : '<=';
					$dbQuery
						->open()
						->select('COUNT(1)')
						->from('favoritee')
						->where('post_id = post.id')
						->close()
						->addSql($operator . ' ?')->put(intval($val));
					break;

				case 'type':
					switch ($val)
					{
						case 'swf':
							$type = PostType::Flash;
							break;
						case 'img':
							$type = PostType::Image;
							break;
						default:
							throw new SimpleException('Unknown type "' . $val . '"');
					}
					$dbQuery
						->addSql('type = ?')
						->put($type);
					break;

				case 'date':
				case 'datemin':
				case 'datemax':
					list ($year, $month, $day) = explode('-', $val . '-0-0');
					$yearMin = $yearMax = intval($year);
					$monthMin = $monthMax = intval($month);
					$monthMin = $monthMin ?: 1;
					$monthMax = $monthMax ?: 12;
					$dayMin = $dayMax = intval($day);
					$dayMin = $dayMin ?: 1;
					$dayMax = $dayMax ?: intval(date('t', mktime(0, 0, 0, $monthMax, 1, $year)));
					$timeMin = mktime(0, 0, 0, $monthMin, $dayMin, $yearMin);
					$timeMax = mktime(0, 0, -1, $monthMax, $dayMax+1, $yearMax);

					if ($key == 'date')
					{
						$dbQuery
							->addSql('upload_date >= ?')
							->and('upload_date <= ?')
							->put($timeMin)
							->put($timeMax);
					}
					elseif ($key == 'datemin')
					{
						$dbQuery
							->addSql('upload_date >= ?')
							->put($timeMin);
					}
					elseif ($key == 'datemax')
					{
						$dbQuery
							->addSql('upload_date <= ?')
							->put($timeMax);
					}
					else
					{
						throw new Exception('Invalid key');
					}

					break;

				case 'fav':
				case 'favs':
				case 'favoritee':
				case 'favoriter':
					$dbQuery
						->exists()
						->open()
						->select('1')
						->from('favoritee')
						->innerJoin('user')
						->on('favoritee.user_id = user.id')
						->where('post_id = post.id')
						->and('user.name = ?')->put($val)
						->close();
					break;

				case 'submit':
				case 'upload':
				case 'uploader':
				case 'uploaded':
					$dbQuery
						->addSql('uploader_id = ')
						->open()
						->select('user.id')
						->from('user')
						->where('name = ?')->put($val)
						->close();
					break;

				case 'order':
					if (substr($val, -4) == 'desc')
					{
						$orderDir = 1;
						$val = rtrim(substr($val, 0, -4), ',');
					}
					elseif (substr($val, -3) == 'asc')
					{
						$orderDir = -1;
						$val = rtrim(substr($val, 0, -3), ',');
					}
					if ($val{0} == '-')
					{
						$orderDir *= -1;
						$val = substr($val, 1);
					}
					if ($neg)
					{
						$orderDir *= -1;
						$dbQuery->addSql('0');
					}
					else
					{
						$dbQuery->addSql('1');
					}

					switch ($val)
					{
						case 'id':
							$orderColumn = 'id';
							break;
						case 'date':
							$orderColumn = 'upload_date';
							break;
						case 'comment':
						case 'comments':
						case 'commentcount':
							$orderColumn = '(SELECT COUNT(1) FROM comment WHERE post_id = post.id)';
							break;
						case 'fav':
						case 'favs':
						case 'favcount':
							$orderColumn = '(SELECT COUNT(1) FROM favoritee WHERE post_id = post.id)';
							break;
						case 'tag':
						case 'tags':
						case 'tagcount':
							$orderColumn = '(SELECT COUNT(1) FROM post_tag WHERE post_id = post.id)';
							break;
						case 'random':
							//seeding works like this: if you visit anything
							//that triggers order other than random, the seed
							//is going to reset. however, it stays the same as
							//long as you keep visiting pages with order:random
							//specified.
							$randomReset = false;
							if (!isset($_SESSION['browsing-seed']))
								$_SESSION['browsing-seed'] = mt_rand();
							$seed = $_SESSION['browsing-seed'];
							$orderColumn = 'SUBSTR(id * ' . $seed .', LENGTH(id) + 2)';
							break;
						default:
							throw new SimpleException('Unknown key "' . $val . '"');
					}
					break;

				default:
					throw new SimpleException('Unknown key "' . $key . '"');
			}
		}

		if ($randomReset)
			unset($_SESSION['browsing-seed']);
		$dbQuery->orderBy($orderColumn . ' ' . ($orderDir == 1? 'DESC' : 'ASC'));
	}
}
