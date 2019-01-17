<?php
/*
Plugin Name: Import thumbnail image from Flickr
Plugin URI: #
Description:
Author: Mark Howells-Mead
Version: 1.3.0
Author URI: http://permanenttourist.ch/
*/

class MHMFlickrToThumbnail
{
	public $post_id = null;
	
	public $flickr_id = null;
	
	public $key = '';
	
	public $version = '1.2.0';
	
	public function __construct()
	{
		$this->key = basename(__DIR__);

		$this->config = array(
			'flickr_key' => get_option('flickr_key'),
			'flickr_secret' => get_option('flickr_secret'),
			'flickr_userid' => get_option('flickr_userid')
		);

		if (!empty($this->config['flickr_key']) && !empty($this->config['flickr_secret']) && !empty($this->config['flickr_userid'])) {
			add_action('post_submitbox_misc_actions', array($this, 'add_checkboxes' ));
			add_action('save_post', array($this, 'import_from_flickr'), 10, 3);
			//add_action('admin_menu', [$this, 'registerSubmenuPage']);
			add_filter('parse_query', [$this, 'filteredPostList'], 10, 1);
			add_action('init', function () {
				$this->importViaGet();
			});
		}
	}

	public function importViaGet()
	{

		if (isset($_GET['getFlickr']) && (int)$_GET['getFlickr']) {
			ini_set('display_errors', 1);
			error_reporting(E_ALL);

			global $wpdb;
			$results = $wpdb->get_results("select post_id from wp_postmeta where meta_value like '%flickr.com%' and meta_key = 'video_ref' LIMIT 0, 25;", ARRAY_A);

			if (!$results) {
				return;
			}

			set_time_limit(600);

			foreach ($results as $key => $value) {
				$this->post_id = (int)$value['post_id'];
				
				$flickr_id = get_post_meta($this->post_id, 'flickr_id', true);


				if (empty($flickr_id)) {
					$flickr_id = get_post_meta($this->post_id, 'video_ref', true);
				}

				if (empty($flickr_id)) {
					return;
				}

				if (filter_var($flickr_id, FILTER_VALIDATE_URL)) {
					$parts = explode('/', $flickr_id);
					$flickr_id = (int)$parts[5];
				}

				$this->flickr_id = $flickr_id;

				$this->getImageFromFlickr();

				$this->dump($this->post_id.' done');
			}
			wp_die();
		}
	}

	public function filteredPostList($query)
	{
		if (is_admin() and $query->query['post_type'] == 'post') {
			if (isset($_GET['flickrtags']) && (int)$_GET['flickrtags'] === 1) {
				$query_vars = &$query->query_vars;
				$query_vars['s'] = '[flickr tags=';
			} elseif (isset($_GET['flickr']) && (int)$_GET['flickr'] === 1) {
				$query_vars = &$query->query_vars;
				$query_vars['s'] = '[flickr id=';
			}
		}
	}

	// public function registerSubmenuPage()
	// {
	// 	add_submenu_page(
	// 		'edit.php',
	// 		'Flickr posts',
	// 		'Flickr posts',
	// 		'manage_options',
	// 		'flickr-posts',
	// 		[$this, 'flickrSubmenuPage']
	// 	);
	// }

	// public function flickrSubmenuPage()
	// {
	// 	echo '<div class="wrap">';
	// 	echo '<h2>' .get_admin_page_title(). '</h2>';
	// 	echo '</div>';
	// }

	public function dump($var, $die = false)
	{
		echo '<pre>' .print_r($var, 1). '</pre>';
		if ($die) {
			die();
		}
	}

	/**
	* Add checkboxes to the Post submit box in wp-admin
	*/
	public function add_checkboxes()
	{
		echo '<div class="misc-pub-section" style="border-top: 1px solid #eee">
            <label><input type="checkbox" value="1" name="' .$this->key. '[import_from_flickr]" /> Import image from Flickr</label>
        </div>';
	}

	private function getRemoteFileContents($url)
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$contents = curl_exec($ch);
		curl_close($ch);
		return $contents;
	}

	/**
	 * Save thumbnail IPTC keywords to the post as post_tag taxonomy entries
	 *
	 * @param int $post_id The ID of the post.
	 * @param post $post the post.
	 */
	public function import_from_flickr($post_id, $post, $update)
	{
		if (isset($_POST[$this->key]) && isset($_POST[$this->key]['import_from_flickr'])) {
			$this->post_id = $post_id;
			
			$flickr_id = get_post_meta($post_id, 'flickr_id', true);

			if (empty($flickr_id)) {
				$flickr_id = get_post_meta($post_id, 'video_ref', true);
			}

			if (empty($flickr_id)) {
				return;
			}

			if (filter_var($flickr_id, FILTER_VALIDATE_URL)) {
				$parts = explode('/', $flickr_id);
				$flickr_id = (int)$parts[5];
			}

			$this->flickr_id = $flickr_id;
			$this->getImageFromFlickr();
		}
	}

	private function getImageFromFlickr()
	{
		$FlickrRequestString = 'https://api.flickr.com/services/rest/?method=flickr.photos.getSizes&extras=original_format&format=json&nojsoncallback=1&api_key='.$this->config['flickr_key'].'&secret='.$this->config['flickr_secret'].'&photo_id='.$this->flickr_id;

		if (($image_data = $this->getRemoteFileContents($FlickrRequestString))) {
			$images = json_decode($image_data, true);

			if ($images) {
				$biggest = $images['sizes']['size'][ count($images['sizes']['size']) - 1 ];
				$source = $biggest['source'];

				$upload_dirs = wp_upload_dir();
				$destination = $upload_dirs['path'];
				
				if (!is_dir($destination)) {
					@mkdir($destination, 0755);
				}
				
				if (is_dir($destination)) {
					$filename = basename($source);

					$targetpath = explode('?', trailingslashit($destination).$filename)[0];

					if (@copy($source, $targetpath) && file_exists($targetpath)) {
						$this->set_post_thumbnail($this->post_id, $targetpath);
						delete_post_meta($this->post_id, 'flickr_id');
						delete_post_meta($this->post_id, 'video_ref');
					}
				}
			}
		}
	}

	public function set_post_thumbnail($post_id, $localfilepath)
	{
		$wp_filetype = wp_check_filetype(basename($localfilepath), null);
		$info = pathinfo($localfilepath);

		$attachment = array(
			'post_mime_type'=> $wp_filetype['type'],
			'post_title' 	=> basename($localfilepath),
			'post_name'		=> $info['filename'],
			'post_status' 	=> 'inherit'
		);
		$attach_id = wp_insert_attachment($attachment, $localfilepath, $post_id);
		if (!$attach_id) {
			return false;
		}

		require_once(ABSPATH . 'wp-admin/includes/image.php');
		$attach_data = wp_generate_attachment_metadata($attach_id, $localfilepath); // this is where the thumbnail images are generated
		wp_update_attachment_metadata($attach_id, $attach_data);
		set_post_thumbnail($post_id, $attach_id);
	}
}

new MHMFlickrToThumbnail();
