<?php
namespace Habari;

class Pages extends Posts
{
	public static function get($paramarray = array()) {
		$defaults = array(
			'content_type' => 'docpage',
			'fetch_class' => 'Page',
		);
		
		$paramarray = array_merge($defaults, Utils::get_params($paramarray));
		return Posts::get( $paramarray );
	}
}

?>