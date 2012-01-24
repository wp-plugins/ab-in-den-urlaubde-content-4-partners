<?php
/*
 Plugin Name: Ab in den Urlaub - Content 4 Partners
 Plugin URI: http://content-partner.ab-in-den-urlaub.de
 Description: Hotelbewertungen und Angebote von ab-in-den-urlaub.de für Ihren Blog. <strong>Bei Aktivierung dieses Plugins wird automatisch eine Seite mit Hotelbewertungen und Angeboten generiert.</strong>
 Author: ab-in-den-urlaub
 Version: 1.7
 */

// default settings
define('CONTENT_4_PARTNERS_VERSION', '1.7');
define('CONTENT_4_PARTNERS_HOST', 'webservice.ab-in-den-urlaub.de');
define('CONTENT_4_PARTNERS_PORT', '80');
define('CONTENT_4_PARTNERS_DIR', '');
define('CONTENT_4_PARTNERS_USER', '');
define('CONTENT_4_PARTNERS_PASSWORD', '');
define('CONTENT_4_PARTNERS_DEFAULT_ID', '');
define('CONTENT_4_PARTNERS_CACHE_LIFETIME', 3600);
define('CONTENT_4_PARTNERS_CACHE_USE', '1');
define('CONTENT_4_PARTNERS_NAMESPACE', 'Partner');
define('CONTENT_4_PARTNERS_PERMALINKS_USE', '0');
define('CONTENT_4_PARTNERS_BEGINTAG', '<!-- begin ab-in-den-urlaub.de content-4-partners -->');
define('CONTENT_4_PARTNERS_ENDTAG', '<!-- end ab-in-den-urlaub.de content-4-partners -->');

define('CONTENT_4_PARTNERS_REDIRECTOR_PATH', 'wp-content/plugins/ab-in-den-urlaubde-content-4-partners/redirect.php');
define('CONTENT_4_PARTNERS_PERMALINK_STRUCTURE', '/([a-zA-Z+-/]*)/([0-9-]*).*$');

// max cache lifetime in seconds (one day = 86400 seconds)
define('CONTENT_4_PARTNERS_CACHE_MAX_LIFETIME', 86400);

// default plugin setting names
define('CONTENT_4_PARTNERS_SETTINGS_GROUP', 'content_4_partners_settings_options');
define('CONTENT_4_PARTNERS_SETTINGS', 'content_4_partners_settings');

// registration data for plugin
define('CONTENT_4_PARTNERS_REGDATA_GROUP', 'content_4_partners_regdata_options');
define('CONTENT_4_PARTNERS_REGDATA', 'content_4_partners_regdata');

// default cache settings
define('CONTENT_4_PARTNERS_DEFAULT_CACHE_LIFETIME', 3600);
define('CONTENT_4_PARTNERS_CACHE_KEY_PREFIX', 'content_4_partners_');

// helper for finding this plugin when links are added to plugin actions
static $content4partners_plugin;
/**
 * Content 4 Partners Plugin.
 */
class Content4Partners
{
    // cached options, always get options via $this->getOptions() !!!!
    var $options = null;

    /**
     * Creates new content 4 partners plugin.
     */
    function Content4Partners()
    {
        // register admin hooks
        add_action('admin_init', array(&$this, 'hookAdminInit'));
        add_action('admin_menu', array(&$this, 'hookAdminMenu'));

        // register activation/deactivation hooks
        register_activation_hook(__FILE__, array(&$this, 'hookAdminPluginActivate'));
        register_deactivation_hook(__FILE__, array(&$this, 'hookAdminPluginDeactivate'));

        // register short cuts
        add_shortcode('content4partners', array(&$this, 'shortCodeContent4Partners'));

        // register plugin action hooks
        add_filter('plugin_action_links', array(&$this, 'hookAddPluginAction'), 10, 2);

        // register title hook
        add_filter('wp_title', array(&$this, 'hookWpTitle'), 99, 3);

        // to manipulate the behavior of the wpseo-plugin
        // user can decide if he wants the wpseo to manipulate the title as well
        if (1 == get_post_meta($this->getPageId(), '_wpseo_edit_only', true)) {
            $optionsseo = get_option('wpseo_options');
            // if there are no wpseo_options the wpseo-plugin may not installed
            if (false === $optionsseo) {
                update_post_meta($this->getPageId(), '_wpseo_edit_title', trim($this->getTitleForId($this->getRegionId())) . ' | ' . get_bloginfo());
            } else {
                update_post_meta($this->getPageId(), '_wpseo_edit_title', trim($this->getTitleForId($this->getRegionId())) . ' ' . $optionsseo['wp_seo_title_separator'] . ' ' . get_bloginfo());
            }
        }

        add_filter('rewrite_rules_array', array(&$this, 'insertRewriteRules'));
    }

    /**
     * do not insert <br />-Tags, remove <p>-Tags
     * @param string $content
     */
    function wpautopnobr($content)
    {
        $result = wpautop($content, false);

        // remove p-Tags
        $offsetBegin = strpos($result, CONTENT_4_PARTNERS_BEGINTAG);
        $offsetEnd = strpos($result, CONTENT_4_PARTNERS_ENDTAG);
        if($offsetBegin !== false && $offsetEnd !== false){
            $length = $offsetEnd - $offsetBegin + strlen(CONTENT_4_PARTNERS_ENDTAG);
            if ($length > 0) {
                $c4pContentOriginal = substr($result, $offsetBegin, $length);
                $c4pContent = substr($result, $offsetBegin, $length);
                $c4pContent = str_replace('<p>', '', $c4pContent);
                $c4pContent = str_replace('</p>', '', $c4pContent);

                $result = str_replace($c4pContentOriginal, $c4pContent, $result);
            }
        }

        return $result;
    }

    /**
     * This method is called when the shortcode [content4partners] is
     * found in any content.
     *
     * @param   $attributes
     * @param   $content
     */
    function shortCodeContent4Partners($attributes, $content = null)
    {
        // aktiviere unseren eigenen Filter
        remove_filter('the_content', 'wpautop');
        add_filter('the_content', array(&$this, 'wpautopnobr'), 99);

        $id = $this->getRegionId($attributes);

        // check again
        if (is_null($id)) {
            return "Keine Region ID angegeben.";
        } else {
            return $this->getContent($id);
        }
    }

    /**
     * Gets the content for given region id.
     *
     * @param   $id    the region id
     */
    function getContent($id)
    {
        $useCache = $this->getCacheUse();

        $key = CONTENT_4_PARTNERS_CACHE_KEY_PREFIX . $id;
        if ($useCache) {
            $content = get_transient($key);

            if ($content !== false && !empty($content)) {
                return $content;
            }
        }

        $content = CONTENT_4_PARTNERS_BEGINTAG;
        $content .= $this->getContentFromRegionId($id);
        $content .= CONTENT_4_PARTNERS_ENDTAG;

        if ($useCache && !empty($content)) {
            $cacheLifetime = $this->getCacheLifetime();

            set_transient($key, $content, $cacheLifetime);
        }

        if (empty($content)) {
            $content = "Fehler beim Abrufen der Daten !";
        }
        return $content;
    }

    /**
     * Grabs content for given url.
     *
     * @param   $url
     * @return
     */
    function getContentFromRegionId($regionId, $action = null)
    {
        $options = $this->getOptions();

        if (CONTENT_4_PARTNERS_HOST == '') {
            // no valid host
            return '';
        }

        if (!isset($action)) {
            $action = $options['action'];
        }

        $content = '';

        $url = 'tcp://' . CONTENT_4_PARTNERS_HOST;
        $fp = fsockopen($url, CONTENT_4_PARTNERS_PORT, $errno, $errstr, 30);
        if (!$fp) {
            // TODO error handling ?
            return $content;
        } else {
            $get = CONTENT_4_PARTNERS_DIR . '/xml.php?namespace=' . $options['namespace']
                . '&action=' . $action . '&param[]=' . $options['user'] . '&param[]='
                . $options['password'] . '&param[]=' . $regionId;
            if ($action == 'getPage') {
                $get .= '&param[]=wordpress' . CONTENT_4_PARTNERS_VERSION;
            }
            fwrite($fp, "GET " . $get . " HTTP/1.0\r\nHost: " . CONTENT_4_PARTNERS_HOST . "\r\nAccept: */*\r\n\r\n");

            $started = false;
            while (!feof($fp)) {
                $line = trim(fgets($fp, 1024));
                if ($started) {
                    $content .= $line . "\r\n";
                } elseif (strlen($line) == 0) {
                    $started = true;
                }
            }
            fclose($fp);

            // replace placeholders with page id
            $pageId = $this->getPageId();
            if ($pageId !== false) {
                $content = preg_replace('|##page_id##|', $pageId, $content);
            }
        }

        return $content;
    }

    /**
     * Gets plugin options.
     *
     * @return  plugin options
     */
    function getOptions()
    {
        if (!isset($this->options)) {
            $this->options = get_option(CONTENT_4_PARTNERS_SETTINGS);
        }

        if (!is_array($this->options)){
            $this->options = array();
        }

        // check values
        $this->options['namespace'] = (isset($this->options['namespace']) ? $this->options['namespace'] : CONTENT_4_PARTNERS_NAMESPACE);
        $this->options['action'] = (isset($this->options['action']) ? $this->options['action'] : 'getPage');
        $this->options['user'] = (isset($this->options['user']) ? $this->options['user'] : CONTENT_4_PARTNERS_USER);
        $this->options['password'] = (isset($this->options['password']) ? $this->options['password'] : CONTENT_4_PARTNERS_PASSWORD);
        $this->options['cache_use'] = (isset($this->options['cache_use']) ? $this->options['cache_use'] : CONTENT_4_PARTNERS_CACHE_USE);
        $this->options['cache_lifetime'] = (isset($this->options['cache_lifetime']) ? $this->options['cache_lifetime'] : CONTENT_4_PARTNERS_CACHE_LIFETIME);
        $this->options['acfpid'] = (isset($this->options['acfpid']) ? $this->options['acfpid'] : CONTENT_4_PARTNERS_DEFAULT_ID);
        $this->options['permalink_use'] = (isset($this->options['permalink_use']) ? $this->options['permalink_use'] : CONTENT_4_PARTNERS_PERMALINKS_USE);

        return $this->options;
    }

    /**
     * Gets lifetime of cache.
     *
     * @return  cache lifetime
     */
    function getCacheLifetime()
    {
        $options = $this->getOptions();

        return intval($options['cache_lifetime']);
    }

    /**
     * Returns true if cache is enabled otherwise false.
     *
     * @return  true if cache is enabled
     */
    function getCacheUse()
    {
        $options = $this->getOptions();

        if (array_key_exists('cache_use', $options)) {
            $useCache = $options['cache_use'] == '1' ? true : false;
        }

        return $useCache;
    }

    /**
     * Gets the region id, will check GET and POST and params array if supplied.
     *
     * @return  region id
     */
    function getRegionId($params = null)
    {
        $id = null;

        // check if parameter id is within get parameters
        if (array_key_exists('acfpid', $_GET)) {
            $id = $_GET['acfpid'];
        } elseif (array_key_exists('acfpid', $_POST)) {
            // check if parameter id is within post parameters
            $id = $_POST['acfpid'];
        } elseif (isset($params) && is_array($params) && array_key_exists('acfpid', $params)) {
            // check if parameter id is in attributes of shortcode
            $id = $params['acfpid'];
        }

        // check for default region id
        if (empty($id)) {
            $options = $this->getOptions();
            $id = $options['acfpid'];
        }

        if (empty($id)) {
            $id = null;
        }

        return $id;
    }

    /**
     * Gets page id.
     *
     * @return  page id
     */
    function getPageId()
    {
        $id = false;
        $currentPage = get_page_by_title(get_the_title());
        if (!is_null($currentPage)) {
            $id = $currentPage->ID;
        }

        if ($id === false) {
            $options = $this->getOptions();

            if (array_key_exists('page_id', $options)) {
                $id = intval($options['page_id']);
            }
        }

        return $id;
    }

    /**
     * Gets the title for given region id.
     *
     * @param   $id     region id
     * @return  title of region
     */
    function getTitleForId($id)
    {
        return $this->getContentFromRegionId($id, 'getPageTitle');
    }

    /**
     * Flushs cache.
     */
    function flushCache()
    {
        global $wpdb;

        // delete entries from options table straight by sql query
        // cache keys match pattern "_transient*content_4_partners_$id"
        $query = "
            DELETE from "
            . $wpdb->options . "
            WHERE option_name like '%_transient%%content_4_partners_%'";

        $wpdb->query($query);
    }

    /**
     * get partner by login and password
     *
     * returns true when login exists or false on error
     * if the result is false, the login is failed
     *
     * @param string $login
     * @param string $password
     *
     * @return boolean
     */
    function loginExists($login, $password)
    {
        global $wpdb;
        global $wp_version;

        $options = $this->getOptions();
        $loginResult = '';
        $url = 'tcp://' . CONTENT_4_PARTNERS_HOST;
        $fp = fsockopen($url, CONTENT_4_PARTNERS_PORT, $errno, $errstr, 30);
        if (!$fp) {
            // TODO error handling ?
            return false;
        } else {
            $get = CONTENT_4_PARTNERS_DIR . '/xml.php?namespace=' . $options['namespace']
                . '&action=login&param[]=' . $login . '&param[]=' . $password . '&NoCache=1';
            fwrite($fp, "GET " . $get . " HTTP/1.0\r\nHost: " . CONTENT_4_PARTNERS_HOST . "\r\nAccept: */*\r\n\r\n");

            $started = false;
            while (!feof($fp)) {
                $line = trim(fgets($fp, 1024));
                if ($started) {
                    $loginResult .= $line . "\r\n";
                } elseif (strlen($line) == 0) {
                    $started = true;
                }
            }
            fclose($fp);
        }

        if (strpos($loginResult, '<login>accepted</login>') !== false) {
            return true;
        } elseif (strpos($loginResult, '<login>') === false) {
            if ((int)$wp_version >= 3) {
                add_settings_error(CONTENT_4_PARTNERS_SETTINGS, 'invalid_connection', __('Verbindungsfehler:<br />' . $loginResult));
            }
        } else {
            return false;
        }
    }

    /**
     * Invalidates current options. Next request will fetch current options.
     */
    function invalidateOptions()
    {
        $this->options = null;
    }

    /**
     * TODO to be implemented
     *
     * This method is called when admin settings are about to get saved.
     * Can be used for validation of input data.
     *
     * @param   $input
     * @return  validated input
     */
    function adminSettingsValidate($input)
    {
        global $wp_version;
        $errors = false;

        if ($_POST) {
            if (array_key_exists('page_name', $_POST)) {
                if (empty($_POST['page_name'])) {
                    $_POST['page_name'] = 'Hotels';
                    $page_name = 'Hotels';
                    $erors = true;
                    if ((int)$wp_version >= 3) {
                        add_settings_error(CONTENT_4_PARTNERS_SETTINGS, 'invalid_page_name', __('Kein Menü-Name angegeben.'));
                    }
                } else {
                    $_POST['page_name'] = trim($_POST['page_name']);
                }
            }
        }

        foreach ($input as $key => $option) {
            $input[$key] = trim($option);
        }

        // check if username is empty
        if (array_key_exists('user', $input)) {
            if (empty($input['user'])) {
                $errors = true;
                if ((int)$wp_version >= 3) {
                    add_settings_error(CONTENT_4_PARTNERS_SETTINGS, 'invalid_username', __('Kein Nutzername angegeben.'));
                }
            } else {
                $input['user'] = trim($input['user']);
            }
        }

        //check if password is empty
        if (array_key_exists('password', $input)) {
            if (empty($input['password'])) {
                $getoption = get_option(CONTENT_4_PARTNERS_SETTINGS);
                $input['password'] = $getoption['password'];
            }
            if (empty($input['password'])) {
                $errors = true;
                if ((int)$wp_version >= 3) {
                    add_settings_error(CONTENT_4_PARTNERS_SETTINGS, 'invalid_password', __('Kein Passwort angegeben.'));
                }
            } else {
                $input['password'] = trim($input['password']);
            }
        }

        //check if region-id is empty
        if (array_key_exists('acfpid', $input)) {
            if (empty($input['acfpid'])) {
                $errors = true;
                if ((int)$wp_version >= 3) {
                    add_settings_error(CONTENT_4_PARTNERS_SETTINGS, 'invalid_regionid', __('Keine Region-ID angegeben.'));
                }
            } else {
                $input['acfpid'] = trim($input['acfpid']);
            }
        }

        //check if login exists for posted username and password
        if (array_key_exists('user', $input) && array_key_exists('password', $input)) {
            if (!$this->loginExists($input['user'], $input['password'])) {
                $errors = true;
                if ((int)$wp_version >= 3) {
                    add_settings_error(CONTENT_4_PARTNERS_SETTINGS, 'invalid_login', __('Kein gültiger Login. Bitte Benutzernamen und Passwort überprüfen.'));
                }
            }
        }

        // check cache lifetime
        if (array_key_exists('cache_lifetime', $input)) {
            if (empty($input['cache_lifetime'])) {
                $errors = true;
                if ((int)$wp_version >= 3) {
                    add_settings_error(CONTENT_4_PARTNERS_SETTINGS, 'invalid_cache_lifetime', __('Keine Cache Lifetime angegeben.'));
                }
            } else {
                $input['cache_lifetime'] = trim($input['cache_lifetime']);

                if (!is_numeric($input['cache_lifetime'])) {
                    $errors = true;
                    if ((int)$wp_version >= 3) {
                        add_settings_error(CONTENT_4_PARTNERS_SETTINGS, 'invalid_cache_lifetime', __('Cache Lifetime darf nur Zahlen enthalten.'));
                    }
                } else {
                    $lifetime = intval($input['cache_lifetime']);

                    if ($lifetime > CONTENT_4_PARTNERS_CACHE_MAX_LIFETIME) {
                        $errors = true;
                        if ((int)$wp_version >= 3) {
                            add_settings_error(CONTENT_4_PARTNERS_SETTINGS, 'invalid_cache_lifetime', __('Maximal erlaubte Lifetime des Cache sind ' . (CONTENT_4_PARTNERS_CACHE_MAX_LIFETIME / 3600) . " Stunden."));
                        }
                    }
                }
            }
        }

        if ((int)$wp_version >= 3) {
            if (count(get_settings_errors(CONTENT_4_PARTNERS_SETTINGS)) > 0) {
                settings_errors(CONTENT_4_PARTNERS_SETTINGS);
                return false;
            }
        } else {
            if ($errors) {
                return false;
            }
        }

        $input['permalink_use'] = (isset($input['permalink_use']) ? $input['permalink_use'] : CONTENT_4_PARTNERS_PERMALINKS_USE);
        $input['cache_use'] = (isset($input['cache_use']) ? $input['cache_use'] : '0');

        return $input;
    }

    /**
     * This method is called when plugin is being activated.
     */
    function hookAdminPluginActivate()
    {
        register_setting(CONTENT_4_PARTNERS_SETTINGS_GROUP,
            CONTENT_4_PARTNERS_SETTINGS);

        // set default settings
        $options = $this->getOptions();

        // create page if user data exists
        $pageId = -1;
        if ($options !== false) {
            if ($options['user'] != '' && $options['password'] != '' && $options['acfpid'] != '') {
                if (array_key_exists('page_id', $options)) {
                    $pageId = $options['page_id'];
                }
                if ($pageId === false || $pageId <= 0) {
                    $pageId = $this->createPage();
                }
                if ($pageId !== false) {
                    // check if page exists
                    $page = get_page_by_title(get_the_title($pageId));
                    if (is_null($page)) {
                        $pageId = $this->createPage();
                    }
                }
            }
        }

        // set default settings
        if ($options === false) {
            $options = array();
            $options['namespace'] = CONTENT_4_PARTNERS_NAMESPACE;
            $options['action'] = 'getPage';
            $options['user'] = CONTENT_4_PARTNERS_USER;
            $options['password'] = CONTENT_4_PARTNERS_PASSWORD;
            $options['cache_use'] = CONTENT_4_PARTNERS_CACHE_USE;
            $options['cache_lifetime'] = CONTENT_4_PARTNERS_CACHE_LIFETIME;
            $options['acfpid'] = CONTENT_4_PARTNERS_DEFAULT_ID;
            $options['permalink_use'] = CONTENT_4_PARTNERS_PERMALINKS_USE;

            update_option(CONTENT_4_PARTNERS_SETTINGS, $options);
        }

        // check permalink settings
        if (!array_key_exists('permalink', $options)) {
            $page = get_page_by_title(get_the_title($pageId));
            if (!is_null($page)) {
                $options['permalink'] = urlencode(site_url() . '/' . $page->post_name);
                update_option(CONTENT_4_PARTNERS_SETTINGS, $options);
            }
        }
    }

    /**
     * This method is called when plugin is being deactivated.
     */
    function hookAdminPluginDeactivate()
    {
        // flush cache
        $this->flushCache();

        // delete page if exists
        $pageId = $this->getPageId();
        $this->deletePage();

        // set options to null, next request will fetch current options from db
        $this->invalidateOptions();

        global $wp_rewrite;
        $wp_rewrite->flush_rules();
    }

    /**
     * This method is called when admin page is being initialized.
     */
    function hookAdminInit()
    {
        register_setting(CONTENT_4_PARTNERS_SETTINGS_GROUP, CONTENT_4_PARTNERS_SETTINGS);
    }

    /**
     * This method is called when admin menu is being created.
     */
    function hookAdminMenu()
    {
        add_submenu_page('plugins.php', 'Content 4 Partners', 'Content 4 Partners', 'manage_options', 'content_4_partners_settings', array(&$this, 'adminMenuDoPage'));
    }

    /**
     * This method adds settings menu beside plugin action menu.
     *
     * @param $links
     * @param $file
     */
    function hookAddPluginAction($links, $file)
    {
        global $content4partners_plugin;

        if (!$content4partners_plugin) {
            $content4partners_plugin = plugin_basename(__FILE__);
        }

        if ($file == $content4partners_plugin) {
            // remove edit link
            foreach ($links as $key => $value) {
                if (strpos($value, 'plugin-editor.php') !== false) {
                    unset($links[$key]);
                }
            }

            // add settings link
            $settings_link = '<a href="plugins.php?page=content_4_partners_settings">Settings&nbsp;</a>';

            $links[] = $settings_link;
        }

        return $links;
    }

    /**
     * Hook to change the html title just for this page.
     *
     * @param $title    title before
     * @return  modified title
     */
    function hookWpTitle($title, $sep, $seplocation)
    {
        $pageId = -1; // init
        $options = $this->getOptions();

        if (false !== $options) {
            if (array_key_exists('page_id', $options)) {
                $pageId = intval($options['page_id']);
            }
        }
        if ($pageId !== false) {
            $currentPage = get_page_by_title(get_the_title());
            if (!is_null($currentPage) && $currentPage->ID == $pageId) {
                $regionId = $this->getRegionId();

                if (!is_null($regionId)) {
                    $newTitle = $this->getTitleForId($regionId);
                    if (!empty($sep)) {
                        return 'right' == $seplocation ? $newTitle . ' ' . $sep . ' ' : ' ' . $sep . ' '  . $newTitle;
                    }
                    return $newTitle;
                }
            }
        }

        return $title;
    }

    /**
     * This method is called when plugin settings page is being displayed.
     */
    function adminMenuDoPage()
    {
?>
		    <div id="c4pp-warning" class="updated highlight">
	        <p><strong>C4PP holds an automatic generated hotel page.</strong>
	        </p>
	        </div>
        <?php
        // flush cache
        if (isset($_POST['action']) && 'clearcache' == $_POST['action']) {
            check_admin_referer('content_4_partners_cache_form');

            $this->flushCache();

?>
            <div id="message" class="updated fade"><p><strong><?php echo _e('Cache flushed'); ?></strong></p></div>
            <?php
        }

        // update settings and menu name
        if (isset($_POST['action']) && 'update_settings' == $_POST['action']) {
            // update options
            if ($_POST[CONTENT_4_PARTNERS_SETTINGS]['password'] != '') {
                $_POST[CONTENT_4_PARTNERS_SETTINGS]['password'] = md5($_POST[CONTENT_4_PARTNERS_SETTINGS]['password']);
            }
            $options = $_POST[CONTENT_4_PARTNERS_SETTINGS];
            $validatedOptions = $this->adminSettingsValidate($options);

            if ($validatedOptions !== false) {
                $pageId = $this->getPageId();
                if ($pageId === false || $pageId <= 0) {
                    $pageId = $this->createPage($options['permalink_use']);
                }
                if ($pageId !== false) {
                    // check if page exists
                    $page = get_page_by_title(get_the_title($pageId));
                    if (is_null($page) || $page->post_status == 'trash') {
                        $pageId = $this->createPage($options['permalink_use']);
                        $page = get_page_by_title(get_the_title($pageId));
                    }
                    $validatedOptions['page_id'] = $pageId;
                    if (!is_null($page)) {
                        //name
                        if (isset($_POST['page_name']) && $page->post_title != $_POST['page_name']) {
                            $page->post_title = $_POST['page_name'];
                            wp_update_post($page);
                        }
                        // permalink
                        $validatedOptions['permalink'] = urlencode(site_url() . '/' . $page->post_name);
                    }
                }

                $loginExists = $this->loginExists($validatedOptions['user'], $validatedOptions['password']);
                if ($loginExists) {
                    $style = 'style="background-color:#99FF99;border-color:#33B200;"';
                    $class = 'updated fade';
                } else {
                    $style = '';
                    $class = 'error';
                }

                $oldOption = $this->getOptions();
                global $wp_rewrite;
                $regex = $page->post_name . CONTENT_4_PARTNERS_PERMALINK_STRUCTURE;
                $redirect = CONTENT_4_PARTNERS_REDIRECTOR_PATH . '?acfpid=$2';
                if ($oldOption['permalink_use'] != $validatedOptions['permalink_use']) {
                    // create rewrite rule
                    if ($validatedOptions['permalink_use']) {
                        add_rewrite_rule($regex, $redirect);
                    } else {
                        // remove rewrite rule
                        remove_filter('rewrite_rules_array', array(&$this, 'insertRewriteRules'));
                    }
                    $wp_rewrite->flush_rules();
                } else {
                    if ($validatedOptions['permalink_use']) {
                        add_rewrite_rule($regex, $redirect);
                        $wp_rewrite->flush_rules();
                    }
                }

                $validatedOptions['permalink'] = urlencode(site_url() . '/' . $page->post_name);
                update_option(CONTENT_4_PARTNERS_SETTINGS, $validatedOptions);

                $this->invalidateOptions();

?>
                <div id="message1" class="updated fade">
                <p>
                <strong><?php echo _e('Settings saved'); ?><br /></strong>
                </p>
                </div>

                <div id="message2" <?php echo $style?> class="<?php echo $class?>">
                <p>
                <strong><?php echo _e(($loginExists) ? 'Login successful' : 'Login failed'); ?><br /></strong>
                </p>
                </div>
                <?php
            }

        }

?>
        <div class="wrap">
        <div class="icon32" id="icon-plugins"></div>
        <h2>Content 4 Partners Options</h2>
            <?php
        $image_aidu_url = plugins_url() . '/ab-in-den-urlaubde-content-4-partners/images/logo.png';
?>
            <form method="post" action="">
                <?php $options = $this->getOptions(); ?>
	        	<table border="0" class="form-table" width="100%">
		            <tr valign="middle">
		                <td>
		                    <h3>Benutzer - Einstellung</h3>
		                </td>
		                <td></td>
		                <td width="40%" style="padding-right:20px;" align="right"><img src="<?php echo $image_aidu_url; ?>" alt="" /></td>
		            </tr>
		        	<tr valign="middle">
		        		<td width="20%">Benutzer</td>
		        		<td><input size="40" type="text"
		        			name="content_4_partners_settings[user]"
		        			value="<?php echo $options['user']; ?>" />
                            <span class="description">benötigt</span></td>
		        		<td width="50%" rowspan="4">
			        		<div>
			        		<p><strong>Für die Eingabe der Benutzer-Einstellung ist einmalig eine Registrierung nötig.
			        		<br /><br />Bitte benutzen Sie hierzu das Registrierungsformular.</strong></p>
			        		</div>
		        		</td>
		        	</tr>
		        	<tr valign="middle">
		        		<td>Passwort</td>
		        		<td><input size="40" type="password"
		        			name="content_4_partners_settings[password]"
		        			value="" />
                            <span class="description"><?php echo($options['password'] == '') ? 'ben&ouml;tigt' : 'bereits gespeichert'; ?></span></td>
		        	</tr>
		        	<tr valign="middle">
		        		<td>Region - ID</td>
		        		<td><input size="40" type="text"
		        			name="content_4_partners_settings[acfpid]"
		        			value="<?php echo $options['acfpid']; ?>" />
                            <span class="description">benötigt</span></td>
		        	</tr>
		            <tr valign="middle">
		                <td>Menü Name</td>
		                <td><input size="40" type="text"
		                    name="page_name"
		                    value="<?php $pagename = get_the_title($this->getPageId()); if($pagename){echo $pagename;}else{echo 'Hotels';} ?>" /></td>
		            </tr>
		            <tr valign="middle">
                        <td width="20%">Permalinks aktiviert</td>
                        <td><input type="checkbox"
                            name="content_4_partners_settings[permalink_use]"
                            value="1" <?php checked('1', $options['permalink_use']); ?> />
                        </td>
                        <td width="50%">
                            <div>
                            <p>Wenn Sie die Permalinks aktivieren, müssen Sie für diese Region den "Permalink"-Client in den <a href="http://content-partner.ab-in-den-urlaub.de/seiten/region/<?php echo $options['acfpid'];?>" target="_aiduc4p" rel="nofollow">Regionseinstellungen</a> auswählen.</p>
                            </div>
                        </td>
                    </tr>
		            <tr valign="middle">
		                <td>
		                    <h3>Cache</h3>
		                </td>
		                <td></td>
		                <td width="50%">
			        		<div>
			        		<p>Sie haben Fragen oder brauchen Hilfe?  In diesem Bereich finden Sie alle Möglichkeiten, sich per E-Mail oder Telefon an uns zu wenden.</p>
			        		</div>
		        		</td>
		            </tr>
		            <tr valign="middle">
		                <td width="20%">Cache Aktiv</td>
		                <td><input type="checkbox"
		                    name="content_4_partners_settings[cache_use]"
		                    value="1" <?php checked('1', $options['cache_use']); ?> />
		                </td>
		            </tr>
		            <tr valign="middle">
		                <td width="20%">Cache Lifetime in Sekunden</td>
		                <td><input size="40" type="text"
		                    name="content_4_partners_settings[cache_lifetime]"
		                    value="<?php echo $options['cache_lifetime']; ?>" />
		                </td>
		                <td>
					        <div class="submit" style="float:left; padding-right:10px;">
					            <a class="button-primary" href="http://content-partner.ab-in-den-urlaub.de/registrieren" target="_blank"><?php _e('Registrierung'); ?></a>
					            <a class="button-primary" href="http://content-partner.ab-in-den-urlaub.de/kontakt" target="_blank">Kontakt</a>
					        	</div>
				        </td>
		            </tr>
		            <tr valign="middle">
		                <td>
		                    <input type="hidden" name="content_4_partners_settings[namespace]"
		                        value="<?php echo $options['namespace']; ?>" />
		                    <input type="hidden" name="content_4_partners_settings[action]"
		                        value="<?php echo $options['action']; ?>" />

		                    <?php if (array_key_exists('page_id', $options)) : ?>
		                        <input type="hidden" name="content_4_partners_settings[page_id]"
		                            value="<?php echo $options['page_id']; ?>" />
		                    <?php endif; ?>

		                    <input type="hidden" name="action" value="update_settings" />
		                </td>
		                <td>
		                </td>
		            </tr>
		            <tr>
		                <td>
		                    <input type="submit" class="button-primary" value="<?php _e('Save') ?>" />
		                </td>
		                <td></td>
		            </tr>
	       		</table>
	       </form>
	       <table class="form-table" width="100%">
            <tr>
                <td width="20%">
                    <h3>weitere Funktionen</h3>
                </td>
                <td>
                    <form name="clearcache" method="post" action="">
		        <?php
        if (function_exists('wp_nonce_field') === true) {
            wp_nonce_field('content_4_partners_cache_form');
        }
?>
			        <div class="submit" style="float:left; padding-right:10px;">
			            <input type="hidden" name="action" value="clearcache" />
			            <input type="submit" class="button-primary" value="<?php _e('Cache leeren'); ?>" />
			        </div>
		        </form>
			        <div style="clear:both;"></div>
                </td>
            </tr>
           </table>

           <?php
        global $wp_rewrite;
        $homePath = get_home_path();
        $iis7s = iis7_supports_permalinks();
        if ($iis7s) {
            if ((!file_exists($homePath . 'web.config') && win_is_writable($homePath)) || win_is_writable($homePath . 'web.config')) {
                $writable = true;
            } else {
                $writable = false;
            }
        } else {
            if ((!file_exists($homePath . '.htaccess') && is_writable($homePath)) || is_writable($homePath . '.htaccess')) {
                $writable = true;
            } else {
                $writable = false;
            }
        }
        $permalink_structure = get_option('permalink_structure');
        if ($iis7s) {
            if ($permalink_structure && !($wp_rewrite->using_index_permalinks()) && !$writable) {
?>
                    <p><?php _e('If your <code>web.config</code> file were <a href="http://codex.wordpress.org/Changing_File_Permissions">writable</a>, we could do this automatically, but it isn&#8217;t so this is the url rewrite rule you should have in your <code>web.config</code> file. Click in the field and press <kbd>CTRL + a</kbd> to select all. Then insert this rule inside of the <code>/&lt;configuration&gt;/&lt;system.webServer&gt;/&lt;rewrite&gt;/&lt;rules&gt;</code> element in <code>web.config</code> file.') ?></p>
                    <form action="options-permalink.php" method="post">
                    <?php wp_nonce_field('update-permalink') ?>
                        <p><textarea rows="10" class="large-text readonly" name="rules" id="rules" readonly="readonly"><?php echo esc_html($wp_rewrite->iis7_url_rewrite_rules()); ?></textarea></p>
                    </form>
                    <p><?php _e('If you temporarily make your <code>web.config</code> file writable for us to generate rewrite rules automatically, do not forget to revert the permissions after rule has been saved.') ?></p>
                <?php } ?>
            <?php } else {
            if ($permalink_structure && !($wp_rewrite->using_index_permalinks()) && !$writable) {
?>
                    <p><?php _e('If your <code>.htaccess</code> file were <a href="http://codex.wordpress.org/Changing_File_Permissions">writable</a>, we could do this automatically, but it isn&#8217;t so these are the mod_rewrite rules you should have in your <code>.htaccess</code> file. Click in the field and press <kbd>CTRL + a</kbd> to select all.') ?></p>
                    <form action="options-permalink.php" method="post">
                    <?php wp_nonce_field('update-permalink') ?>
                        <p><textarea rows="6" class="large-text readonly" name="rules" id="rules" readonly="readonly"><?php echo esc_html($wp_rewrite->mod_rewrite_rules()); ?></textarea></p>
                    </form>
                <?php } ?>
        </div>
        <?php
        }
    }

    /**
     * Creates the C4P-content-page.
     */
    function createPage($permalink_use = '0')
    {
        $options = $this->getOptions();
        $pageId = false;

        // create default page for content 4 partners
        $page = array();
        $page['post_title'] = 'Hotels';
        $page['post_name'] = 'hotels';
        $page['post_type'] = 'page';
        $page['post_content'] = '[content4partners]';
        $page['post_status'] = 'publish';
        $page['ping_status'] = 'closed';
        $page['comment_status'] = 'closed';

        // insert the page into the database
        $pageId = wp_insert_post($page);

        // remember settings
        if ($pageId != 0) {
            $options['page_id'] = $pageId;

            $newPage = get_page_by_title(get_the_title($pageId));
            if (!is_null($newPage)) {
                $page['post_name'] = $newPage->post_name;
                $options['permalink'] = urlencode(site_url() . $newPage->post_name);
            }

            update_option(CONTENT_4_PARTNERS_SETTINGS, $options);
            update_post_meta($pageId, '_wpseo_edit_only', '1');
        }

        if ('1' == $permalink_use) {
            // create rewrite rule
            $regex = $page->post_name . CONTENT_4_PARTNERS_PERMALINK_STRUCTURE;
            $redirect = CONTENT_4_PARTNERS_REDIRECTOR_PATH . '?acfpid=$2';
            add_rewrite_rule($regex, $redirect);
            global $wp_rewrite;
            $wp_rewrite->flush_rules();
        }

        return $pageId;
    }

    /**
     * delete the C4P-content-page if exists
     */
    function deletePage()
    {
        // delete page if exists
        $pageId = $this->getPageId();

        if ($pageId !== false) {
            wp_delete_post($pageId, true);
        }

        // very important, delete page_id from options
        $options = $this->getOptions();
        $options['page_id'] = -1;
        update_option(CONTENT_4_PARTNERS_SETTINGS, $options);
        delete_post_meta($pageId, '_wpseo_edit_only', '1');

        // set options to null, next request will fetch current options from db
        $this->invalidateOptions();
    }

    function insertRewriteRules($rules)
    {
        $options = $this->getOptions();

        if ('1' == $options['permalink_use']) {
            $newrules = array();
            $pageTitle = get_the_title($this->getPageId());
            $page = get_page_by_title($pageTitle);
            // create rewrite rule
            $regex = $page->post_name . CONTENT_4_PARTNERS_PERMALINK_STRUCTURE;
            $redirect = CONTENT_4_PARTNERS_REDIRECTOR_PATH . '?acfpid=$2';
            $newrules[$regex] = $redirect;

            add_rewrite_rule($regex, $redirect);
            return $newrules + $rules;
        } else {
            return $rules;
        }
    }
}

$content4Partners = new Content4Partners();
