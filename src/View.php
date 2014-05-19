<?php
class View extends \Chibi\View
{
	public static function renderTopLevel($viewName, $assets)
	{
		$context = Core::getContext();
		$view = new View($viewName);
		$view->registerDecorator(new \Chibi\Util\Minify());
		$view->registerDecorator($assets);
		$view->context = $context;
		$view->assets = $assets;
		$view->render();
	}

	protected function renderExternal($viewName)
	{
		$view = new View($viewName);
		$view->context = $this->context;
		$view->assets = $this->assets;
		$view->render();
	}
}