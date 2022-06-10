<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

use Doctrine\Common\Cache\ArrayCache;
use Masterminds\HTML5;
use CSSFromHTMLExtractor\CssFromHTMLExtractor;

class Critical_css {

	protected $ci;
	protected $css_extractor;
	protected $optimized_css_path;
	protected $optimize_css_file;
	protected $added_folder;
	protected $errors;
	protected $file_name;
	protected $html;
	protected $config;

	function __construct() {
		$this->ci =& get_instance();
		$this->ci->load->config('critical_css');
		$this->config = $this->ci->config->item('critical_css');
		$this->errors = [];

		$this->optimized_css_path = $this->config['base_path']['site'];
		$this->css_extractor = new CssFromHTMLExtractor;
	}

	function html_snippets($link=[], $html=NULL, $file_name=NULL){

		if(!is_array($link)) $link[] = $link;

		$this->file_name = $file_name; 

		$this->set_css_content($link);
		$this->set_html_content($html);

		return $this;
	}

	function page($url=NULL, $file_name=NULL){
		if(!$this->ci->form_validation->valid_url($url)) $this->errors[] = 'Please provide valid page url.';

		if(str_contains($url, 'mobile')) $this->optimized_css_path = $this->config['base_path']['mobile'];

		$this->file_name = $file_name;
		$html_dom = $this->fetch_html($url);

		$links = $this->extract_css_links($html_dom->content);

		$this->set_css_content($links);
		$this->set_html_content($html_dom->content);

		return $this;
	}

	function generate($return_as_string=FALSE) {

		if(!$return_as_string AND !$this->file_name) $this->errors[] = 'Please provide file name of css file.';

		if(!$this->has_error()){
			$extracted_css = $this->get_compiled_css();
			
			if($this->check_and_create_folders() AND !$return_as_string){
				$file_path = $this->generate_file_path();
				$this->delete_files($this->file_name);

				if(!file_exists($file_path)) {
					if(write_file($file_path, $extracted_css)) return TRUE;
				}
			}
			else return $extracted_css;
		}
	}

	function delete_files($file_name=NULL, $target_path=NULL){
		$target_path = ($target_path) ?: $this->generate_folder_path();
		$has_error = TRUE;

		if(is_dir($target_path)){
			if($file_name){
				$target_file = sprintf('%1$s/%2$s.blade.php', $target_path, $file_name);
				if(file_exists($target_file)){
					if(unlink($target_file)) $has_error = FALSE;
				}
			}
			else{
				$files = directory_map($target_path);
				if(is_array($files)){
					foreach($files as $folder=>$file){
						if(is_array($file)){
							$has_error = $this->delete_files(NULL, sprintf('%1$s/%2$s', $target_path, rtrim($folder, '\\')));
						}
						else{
							if(unlink(sprintf('%1$s/%2$s', $target_path, $file))) $has_error = FALSE;
						}
					}
				}
			}
		}

		return (!$has_error) ? TRUE : FALSE;;
	}

	function delete_folders($target_path=NULL){
		$target = ($target_path) ?: $this->generate_folder_path();
        if(is_dir($target)){
        	$this->delete_files();
        	$folders = directory_map($target);
        	if(count($folders)){
	        	foreach($folders as $folder_name=>$files){
	        		$path = sprintf('%1$s/%2$s', $target, rtrim($folder_name, '\\'));
	        		$this->delete_folders($path);
	        	}
        	}
        	else {
        		rmdir($target);
        		$this->delete_folders();
        	}
        }  
	}

	function generate_file_path(){
		if(is_null($this->optimize_css_file)) $this->optimize_css_file = sprintf('%1$s/%2$s.blade.php', $this->generate_folder_path(), $this->file_name);
		return $this->optimize_css_file;
	}

	function add_on_folders($folder_path=NULL){
		if($folder_path) $this->added_folder = $folder_path;
		return NULL;
	}

	function errors(){
		return $this->errors;
	}

	function has_error(){
		if(count($this->errors)) return TRUE;
		return FALSE;
	}

	protected function get_compiled_css(){
		$str_styles = NULL;
		if(!$this->has_error()){
			$str_styles = $this->convert_root_with_base_url($this->css_extractor->buildExtractedRuleSet());	
			$str_styles = sprintf('<style type="text/css">%2$s%1$s%2$s</style>', $str_styles, "\r\n");
		} 
		return $str_styles;
	}

	protected function set_css_content($links=[]){
		if(count($links)){
			foreach($links as $link){
				$css_content = read_file($link);
				$this->css_extractor->addBaseRules($css_content);
			}
		} 
		else $this->errors[] = 'No css links provided.';
	}

	protected function set_html_content($html=NULL){

		if($html) $this->css_extractor->addHtmlToStore($html);
		else $this->errors[] = 'No html snippets provided.';
	}

	protected function fetch_html($page_url=NULL){
		$curl = curl_init();
		$response = [
			'http_code' => '500'
		];

		curl_setopt($curl, CURLOPT_URL, $page_url);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($curl, CURLOPT_HEADER, FALSE);

		$content = curl_exec($curl);

		if(!curl_errno($curl)){
			$info = curl_getinfo($curl);
			$response['http_code'] = $info['http_code'];
			$response['content_type'] = $info['content_type'];
			$response['content'] = $content;
		}		
		curl_close($curl);

		if(in_array($response['http_code'], ['500', '404'])) $this->errors[] = 'An error occurred while fetching page.';

		return (object)$response;
	}

	protected function extract_css_links($html_snippets=NULL){
		$html5 = new HTML5();
		$document = $html5->loadHTML($html_snippets);
		$links = [];

		foreach($document->getElementsByTagName('link') as $link_tag) {
			if($link_tag->getAttribute('rel') == 'stylesheet') {
				$tokenised_stylesheet = explode('?', $link_tag->getAttribute('href')); // convert css url into array delimiter ?
                $stylesheet = reset($tokenised_stylesheet); // remove concat version of styles

                $links[] = $stylesheet; 
			}
		}

		return $links;
	}

	protected function check_and_create_folders() {
		$folder_path = $this->generate_folder_path();

		if(!is_dir($folder_path)) {
			$path_arr = explode('/', $folder_path);
			foreach ($path_arr as $segment) {
				$segment_path[] = $segment;
				$target = join('/', $segment_path);
				if(!is_dir($target)) mkdir($target);
			}
		}

		if(is_dir($folder_path)) return TRUE;

		return FALSE;
	}

	protected function convert_root_with_base_url($str=NULL){
		$pattern = '/(\.\.\/){1,}/';
		$str = preg_replace($pattern, '{{ site_url() }}assets/', $str);
		return $str;
	}

	protected function generate_folder_path(){
		$path = NULL;
		if($this->added_folder) $path = sprintf('%1$s/%2$s', $this->optimized_css_path, $this->added_folder);
		else $path = $this->optimized_css_path;

		return $path;
	}
}