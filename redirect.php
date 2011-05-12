<?php
/**
 * @package content_4_partners
 */
/**
 * classname
 *
 * @category
 * @package     package
 * @since       13.10.2010
 * @version     $Id$
 * @copyright   2011 Unister GmbH
 */
class Contentpartner_Redirect
{
    private $_params = array();
    private $_options;

    public function __construct(){
        $this->_options = get_option('content_4_partners_settings');
        $this->_extractParams();
    }

    private function _extractParams()
    {
        if (array_key_exists('page_id', $this->_options)) {
                $id = intval($this->_options['page_id']);
        }
        $pageTitle = get_the_title($id);
        $page = get_page_by_title($pageTitle);
        if(!empty($page)){
	    	$this->_params['permalink'] = urldecode(get_site_url() . '/' . $page->post_name);
        }
    }

    public function showPage($id)
    {
        $options = array('method' => 'GET', 'timeout' => 10);
        $url = $this->_params['permalink'].'?acfpid='.$id;

	    $response = wp_remote_request($url, $options);

	    if(!is_wp_error( $response ) && $response['response']['code'] == 200){
	        echo $response['body'];
	    } else {
	        include('./index.php');
	    }
    }
}

/**
 * fire...
 */
chdir('../../../');
require_once('./wp-config.php');

// get our id
$acfpid = addslashes(strip_tags($_GET['acfpid']));

if (empty($acfpid)) {
    $options = get_option('content_4_partners_settings');
    $acfpid = $options['acfpid'];
}
$redirector = new Contentpartner_Redirect();
$redirector->showPage($acfpid);
