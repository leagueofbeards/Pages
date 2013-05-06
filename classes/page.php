<?php
namespace Habari;

class Page extends Post
{
	public static function create($paramarray = array()) {
		$post = new Page( $paramarray );
		$post->insert();
		return $post;
	}
	
	public static function get($paramarray = array()) {
		$defaults = array(
			'content_type' => 'docpage',
			'fetch_fn' => 'get_row',
			'limit' => 1,
			'fetch_class' => 'Page',
		);
		
		$paramarray = array_merge($defaults, Utils::get_params($paramarray));
		return Posts::get( $paramarray );
	}

	public function jsonSerialize() {
		$array = array_merge( $this->fields, $this->newfields );		
		return json_encode($array);
	}
}?>
