<?php
namespace Habari;

class PagesPlugin extends Plugin
{	
	public function filter_autoload_dirs($dirs) {
		$dirs[] = __DIR__ . '/classes';
		return $dirs;
	}
	
	public function action_init() {
		Format::apply( 'do_highlight', 'post_content_out' );
		DB::register_table( 'pages' );
		$this->add_templates();
	}
	
	public function action_plugin_activation( $plugin_file ) {
		Post::add_new_type( 'docpage' );
		$this->create_pages_table();
	}

	public function action_plugin_deactivation ( $file='' ) {}

	private function add_templates() {
		$this->add_template( 'docpage.new', dirname(__FILE__) . '/views/docpage.new.php' );
		$this->add_template( 'docpage.single', dirname(__FILE__) . '/views/docpage.single.php' );
		$this->add_template( 'docpage.sidebar', dirname(__FILE__) . '/views/docpage.sidebar.php' );
	}

	private function create_pages_table() {
		$sql = "CREATE TABLE {\$prefix}pages (
				id int unsigned NOT NULL AUTO_INCREMENT,
				post_id int unsigned NOT NULL,
				client_id int unsigned NOT NULL,
				document_id int unsigned NOT NULL,
				approved int unsigned NOT NULL,
				name varchar(255) NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `post_id` (`post_id`),
				KEY `client_id` (`client_id`),
				KEY `document_id` (`document_id`)
				) DEFAULT CHARACTER SET utf8 COLLATE utf8_bin;";

		DB::dbdelta($sql);
	}

	public function filter_posts_get_paramarray($paramarray) {
		$queried_types = Posts::extract_param($paramarray, 'content_type');
		if($queried_types && in_array('docpage', $queried_types)) {
			$paramarray['post_join'][] = '{pages}';
			$default_fields = isset($paramarray['default_fields']) ? $paramarray['default_fields'] : array();
			$default_fields['{pages}.client_id'] = '';
			$default_fields['{pages}.document_id'] = 0;
			$default_fields['{pages}.approved'] = 0;
			$default_fields['{pages}.name'] = '';
			$paramarray['default_fields'] = $default_fields;
		}
		return $paramarray;
	}

	public function filter_post_schema_map_docpage($schema, $post) {
		$schema['pages'] = $schema['*'];
		$schema['pages']['post_id'] = '*id';
		return $schema;		
	}

	public function filter_default_rewrite_rules( $rules ) {
		$this->add_rule('"api"/slug/"new"', 'display_create');
/* 		$this->add_rule('"api"/slug/page', 'display_docpage'); */

		$docs = Documents::get( array('nolimit' => true) );
		$keys = array();
		foreach( $docs as $doc ) {
			$keys[] = $doc->slug;
		}
		
		$doc_regex = implode('|', $keys);
		// create the post display rule for one addon
		$rule = array(
			'name' => "display_docpage",
			'parse_regex' => "#^api/(?P<doc>{$doc_regex})/(?P<slug>[^/]+)/?$#i",
			'build_str' => 'api/{$doc}/{$slug}',
			'handler' => 'PluginHandler',
			'action' => 'display_docpage',
			'parameters' => serialize( array( 'require_match' => array( 'Habari\Posts', 'rewrite_match_type' ), 'content_type' => 'docpage' ) ),
			'description' => "Display an addon catalog post of a particular type",
		);
		
		$rules[] = $rule;

		return $rules;
	}

	public function filter_post_content_out( $content, $post ) {
		if($post->content_type == Post::type('docpage') ) {
			$tokenizer = new HTMLTokenizer( $content, false );
			$tokens = $tokenizer->parse();
			$slices = $tokens->slice( array('pre'), array() );
			if( is_array($slices) ) {
				foreach ($slices as $slice) {
					$slice->trim_container();
					$sliceValue = trim( (string)$slice );
					$output = Utils::htmlspecialchars( $sliceValue );
					$slice->tokenize_replace( '<pre>' . $output . '</pre>' );
					$tokens->replace_slice( $slice );
				}
				
				return (string) $tokens;
			} else {
				return $content;
			}
		} else {
			return $content;
		}
	}


	public function theme_route_display_create($theme) {
		$theme->document = Document::get( array('slug' => $theme->matched_rule->named_arg_values['slug']) );
		$theme->pages = Pages::get( array('document_id' => $theme->document->id, 'orderby' =>  'id ASC') );
		$theme->title = 'Create a new page in ' . $theme->document->title;
		
		$theme->display( 'docpage.new' );
	}

	public function theme_route_display_docpage($theme, $params) {
		$theme->document = Document::get( array('slug' => $params['doc']) );
		$theme->page = Page::get( array('document_id' => $theme->document->id, 'name' => $params['slug']) );
		$theme->pages = Pages::get( array('document_id' => $theme->document->id, 'orderby' =>  'id ASC') );
		$theme->title = $theme->document->title . ' &raquo; ' . $theme->page->title;
		$theme->post_id = $theme->page->id;

		$i = 0;
		$str = '';
		$dom = new HTMLDoc( $theme->page->content );
		
		foreach( $dom->find('h4') as $hs ) {
			$slug = Utils::slugify( $hs->node->nodeValue );
			$hs->id = $slug;
			$str .= '<li><a href="' . URL::get('display_docpage', array('doc' => $theme->document->slug, 'slug' => $theme->page->name)) . '#' . $slug . '">' . $hs->node->nodeValue . '</a></li>';
			$i++;
		}
		
		$theme->page->content = $dom->get();
				
		if( $i >= 2 ) {
			$theme->menu = $str;
		} else {
			$theme->menu = null;
		}
		
		$theme->display( 'docpage.single' );
	}

	public function action_auth_ajax_create_page($data) {
		$vars = $data->handler_vars;
		$user = User::identify();
		$doc = Document::get( array('id' => $vars['doc']) );
				
		$args = array(
					'title'			=>	strip_tags($vars['title']),
					'slug'			=>	Utils::slugify( strip_tags($vars['title']) ),
					'content'		=>	$vars['content'] ? $vars['content'] : '',
					'user_id'		=>	$user->id,
					'pubdate'		=>	DateTime::date_create( date(DATE_RFC822) ),
					'status'		=>	Post::status('published'),
					'content_type'	=>	Post::type('docpage'),
					'client_id'		=>	$vars['client_id'] ? $vars['client_id'] : '',
					'document_id'	=>	$vars['doc'],
					'name'			=>	Utils::slugify( strip_tags($vars['title']) )
				);
		
		try {
			$page = Page::create( $args );
			$page->grant( $user, 'full' );
			$status = 200;
			$message = 'Your page has been created';
		} catch( Exception $e ) {
			$status = 401;
			$message = 'We couldn\'t create your page, please try again.' ;
		}
				
		$ar = new AjaxResponse( $status, null, null );
		$ar->callback = 'window.location = "' . URL::get('display_docpage', array('slug' => $doc->slug, 'page' => $page->name)) . '"';
		$ar->out();
	}
	
	public function action_auth_ajax_update_page($data) {
		$vars = $data->handler_vars;
		var_dump($vars); exit();
		$page = Post::get( array('id' => $vars['id']) );
		
		var_dump($page); exit();
		
		$page->title = strip_tags( $vars['title'] );
		$page->content = $vars['content'];
		
		try {		
			$page->update();
			$status = 200;
			$message = $page->title . ' was updated.';
		} catch( Exception $e ) {
			$status = 401;
			$message = 'There was an error updating' . $page->title;
		}

		$ar = new AjaxResponse( $status, $message, null );
		$ar->html('#pages', '#');
		$ar->out();
	}
}
?>
