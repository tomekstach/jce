<?php

/**
 * @package   	JCE
 * @copyright 	Copyright (c) 2009-2015 Ryan Demmer. All rights reserved.
 * @license   	GNU/GPL 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * JCE is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 */
defined('_JEXEC') or die('RESTRICTED');

// Load class dependencies
wfimport('editor.libraries.classes.plugin');
wfimport('editor.libraries.classes.browser');

class WFMediaManagerBase extends WFEditorPlugin {

    protected $_filetypes = 'jpg,jpeg,png,gif';

    private static $browser = array();

    /**
     * @access  public
     */
    public function __construct($config = array()) {
        // use the full "manager" layout by default
        if (!array_key_exists('layout', $config)) {
            $config['layout'] = 'manager';
        }

        if (!array_key_exists('view_path', $config)) {
            $config['view_path'] = WF_EDITOR_LIBRARIES . '/views/plugin';
        }

        if (!array_key_exists('template_path', $config)) {
            $config['template_path'] = WF_EDITOR_LIBRARIES . '/views/plugin/tmpl';
        }

        // Call parent
        parent::__construct($config);

        // initialize the browser
        $browser = $this->getFileBrowser();
        $request = WFRequest::getInstance();

        // Setup plugin XHR callback functions
        $request->setRequest(array($this, 'getDimensions'));
        $request->setRequest(array($this, 'getFileDetails'));
    }

    /**
     * Get the File Browser instance
     * @access public
     * @return object WFBrowserExtension
     */
    protected function getFileBrowser() {
        $name = $this->getName();

        if (!isset(self::$browser[$name])) {
            self::$browser[$name] = new WFFileBrowser($this->getFileBrowserConfig());
        }

        return self::$browser[$name];
    }

    protected function addFileBrowserAction($name, $options = array()) {
        $this->getFileBrowser()->addAction($name, $options);
    }

    protected function addFileBrowserButton($type = 'file', $name, $options = array()) {
        $this->getFileBrowser()->addButton($type, $name, $options);
    }

    protected function addFileBrowserEvent($name, $function = array()) {
      $this->getFileBrowser()->addEvent($name, $function);
    }

	public function getBrowser() {
        return $this->getFileBrowser();
    }

    /**
     * Display the plugin
     * @access public
     */
    public function display() {
        parent::display();

        $document = WFDocument::getInstance();

        $view = $this->getView();
        $browser = $this->getFileBrowser();

        $browser->display();
        $view->assign('filebrowser', $browser);

        // set global options
        $document->addScriptDeclaration('FileBrowser.options=' . json_encode($browser->getProperties()) . ';');
    }

    public function getFileTypes() {
        return $this->getFileBrowser()->getFileTypes();
    }

    protected function setFileTypes($filetypes) {
        return $this->getFileBrowser()->setFileTypes($filetypes);
    }

    private function getFileSystem() {
        $filesystem = $this->getParam('filesystem.name', '', '', 'string', false);

        // if an object, get the name
        if (is_object($filesystem)) {
            $filesystem = isset($filesystem->name) ? $filesystem->name : 'joomla';
        }

        // if no value, default to "joomla"
        if (empty($filesystem)) {
            $filesystem = 'joomla';
        }

        return $filesystem;
    }

    protected function getID3Instance() {
        static $id3;
        if (!is_object($id3)) {
            if (!class_exists('getID3')) {
                $app = JFactory::getApplication();
                // set tmp directory
                define('GETID3_TEMP_DIR', $app->getCfg('tmp_path'));

                require_once(WF_EDITOR_LIBRARIES. '/classes/vendor/getid3/getid3/getid3.php' );
            }

            $id3 = new getID3();
        }
        return $id3;
    }

    protected function id3Data($path) {
        jimport('joomla.filesystem.file');
        clearstatcache();

        $meta = array('width' => '', 'height' => '', 'time' => '');

        $ext = JFile::getExt($path);

        // Initialize getID3 engine
        $id3 = $this->getID3Instance();
        // Get information from the file
        $fileinfo = @$id3->analyze($path);
        getid3_lib::CopyTagsToComments($fileinfo);

        // Output results
        if (isset($fileinfo['video'])) {
            $meta['width']  = isset($fileinfo['video']['resolution_x']) ? round($fileinfo['video']['resolution_x']) : 100;
            $meta['height'] = isset($fileinfo['video']['resolution_y']) ? round($fileinfo['video']['resolution_y']) : 100;
        }

        if (isset($fileinfo['playtime_string'])) {
            $meta['time'] = $fileinfo['playtime_string'];
        }

        if ($ext == 'swf' && $meta['x'] == '') {
            $size = @getimagesize($path);
            $meta['width'] = round($size[0]);
            $meta['height'] = round($size[1]);
        }
        if ($ext == 'wmv' && $meta['x'] == '') {
            $meta['width'] = round($fileinfo['asf']['video_media']['2']['image_width']);
            $meta['height'] = round(( $fileinfo['asf']['video_media']['2']['image_height'] ) + 60);
        }

        return $meta;
    }

    public function getDimensions($file) {
        $browser    = $this->getFileBrowser();
        $filesystem = $browser->getFileSystem();

        $path = WFUtility::makePath($filesystem->getBaseDir(), rawurldecode($file));

        $data = array();

        // images
        if (preg_match('#\.(jpg|jpeg|png|gif|bmp|wbmp|tif|tiff|psd|ico)$#i', $file)) {
            list($data['width'], $data['height']) = getimagesize($path);

            return $data;
        }

        // video and audio
        if (preg_match('#\.(avi|wmv|wm|asf|asx|wmx|wvx|mov|qt|mpg|mpeg|m4a|swf|dcr|rm|ra|ram|divx|mp4|ogv|ogg|webm|flv|f4v|mp3|ogg|wav|xap)$#i', $file)) {

          // only process local files
          if ($filesystem->get('local')) {
            $data = $this->id3Data($path);
            $data['duration'] = preg_match('/([0-9]+):([0-9]+)/', $data['time']) ? $data['time'] : '--:--';

            unset($data['time']);

            return $data;
          }
        }

        return $data;
    }

    /**
     * Get the Media Manager configuration
     * @access private
     * @return array
     */
    protected function getFileBrowserConfig($config = array()) {
        $filetypes  = $this->getParam('extensions', $this->get('_filetypes'));
        $textcase   = $this->getParam('editor.websafe_textcase', 'any');

        if (!empty($textcase) && is_array($textcase)) {
            $textcase = count($textcase) > 1 ? 'any' : array_shift($textcase);
        }

        $base = array(
            'dir' => $this->getParam('dir', 'images', '', 'string', false),
            'filesystem'  => $this->getFileSystem(),
            'filetypes'   => $filetypes,
            'upload' => array(
                'max_size'          => $this->getParam('max_size', 1024, '', 'string', false),
                'validate_mimetype' => (int) $this->getParam('editor.validate_mimetype', 1),
                'add_random'        => (int) $this->getParam('editor.upload_add_random', 0),
                'total_files'       => (float) $this->getParam('editor.total_files', 0),
                'total_size'        => (float) $this->getParam('editor.total_size', 0),
                'remove_exif'       => (int) $this->getParam('editor.upload_remove_exif', 0)
            ),
            'folder_tree' => $this->getParam('editor.folder_tree', 1),
            'list_limit' => $this->getParam('editor.list_limit', 'all'),
            'use_cookies' => $this->getParam('editor.use_cookies', true),
            'features' => array(
                'upload' => $this->getParam('upload', 1),
                'folder' => array(
                    'create'  => $this->getParam('folder_new', 1),
                    'delete'  => $this->getParam('folder_delete', 1),
                    'rename'  => $this->getParam('folder_rename', 1),
                    'move'    => $this->getParam('folder_move', 1)
                ),
                'file' => array(
                    'delete'  => $this->getParam('file_delete', 1),
                    'rename'  => $this->getParam('file_rename', 1),
                    'move'    => $this->getParam('file_move', 1)
                )
            ),
            'websafe_mode' => $this->getParam('editor.websafe_mode', 'utf-8'),
            'websafe_spaces' => $this->getParam('editor.websafe_allow_spaces', 0),
            'websafe_textcase' => $textcase,
            'date_format' => $this->getParam('editor.date_format', '%d/%m/%Y, %H:%M'),
            'position' => $this->getParam('editor.filebrowser_position', $this->getParam('editor.browser_position', 'bottom'))
        );

        return WFUtility::array_merge_recursive_distinct($base, $config);
    }
}
