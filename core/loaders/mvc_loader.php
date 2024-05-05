<?php

abstract class MvcLoader {
    public $plugin_name;
    public $settings_names;

    public $plugin_app_paths;
    protected $admin_controller_names = array();
    protected $admin_controller_capabilities = array();
    protected $core_path = '';
    protected $dispatcher = null;
    protected $file_includer = null;
    protected $model_names = array();
    protected $public_controller_names = array();
    protected $query_vars = array();

    function __construct() {
    
        if (!defined('MVC_CORE_PATH')) {
            define('MVC_CORE_PATH', MVC_PLUGIN_PATH.'core/');
        }
        
        $this->core_path = MVC_CORE_PATH;

        $this->query_vars = array('mvc_controller','mvc_action','mvc_id','mvc_extra','mvc_layout');
        
        $this->load_core();
        $this->load_plugins();
        
        $this->file_includer = new MvcFileIncluder();
        $this->file_includer->include_all_app_files('config/bootstrap.php');
        $this->file_includer->include_all_app_files('config/routes.php');

        $this->dispatcher = new MvcDispatcher();

        $this->plugin_name = MvcObjectRegistry::get_object('plugin_name');
        if (! isset($this->plugin_name)) {
            $this->plugin_name = '';
        }

    }

    
    protected function load_core() {
        
        $files = array(
            'mvc_error',
            'mvc_configuration',
            'mvc_directory',
            'mvc_dispatcher',
            'mvc_file',
            'mvc_file_includer',
            'mvc_model_registry',
            'mvc_object_registry',
            'mvc_settings_registry',
            'mvc_plugin_loader',
            'mvc_templater',
            'mvc_inflector',
            'mvc_router',
            'mvc_settings',
            'controllers/mvc_controller',
            'controllers/mvc_admin_controller',
            'controllers/mvc_public_controller',
            'functions/functions',
            'models/mvc_database_adapter',
            'models/mvc_database',
            'models/mvc_data_validation_error',
            'models/mvc_data_validator',
            'models/mvc_model_object',
            'models/mvc_model',
            'models/wp_models/mvc_comment',
            'models/wp_models/mvc_comment_meta',
            'models/wp_models/mvc_post_adapter',
            'models/wp_models/mvc_post',
            'models/wp_models/mvc_post_meta',
            'models/wp_models/mvc_user',
            'models/wp_models/mvc_user_meta',
            'helpers/mvc_helper',
            'helpers/mvc_form_tags_helper',
            'helpers/mvc_form_helper',
            'helpers/mvc_html_helper',
            'shells/mvc_shell',
            'shells/mvc_shell_dispatcher'
        );
        
        foreach ($files as $file) {
            require_once $this->core_path.$file.'.php';
        }
        
    }
    
    protected function load_plugins() {
    
        $plugins = $this->get_ordered_plugins();
        $plugin_app_paths = array();
        foreach ($plugins as $plugin) {
            $plugin_app_paths[$plugin] = rtrim(WP_PLUGIN_DIR, '/').'/'.$plugin.'/app/';
        }

        MvcConfiguration::set(array(
            'Plugins' => $plugins,
            'PluginAppPaths' => $plugin_app_paths
        ));

        $this->plugin_app_paths = $plugin_app_paths;
    
    }
    
    protected function get_ordered_plugins() {
    
        $plugins = get_option('mvc_plugins', array());
        $plugin_app_paths = array();
        
        // Allow plugins to be loaded in a specific order by setting a PluginOrder config value like
        // this ('all' is an optional token; it includes all unenumerated plugins):
        // MvcConfiguration::set(array(
        //      'PluginOrder' => array('my-first-plugin', 'my-second-plugin', 'all', 'my-last-plugin')
        // );
        $plugin_order = MvcConfiguration::get('PluginOrder');
        if (!empty($plugin_order)) {
            $ordered_plugins = array();
            $index_of_all = array_search('all', $plugin_order);
            if ($index_of_all !== false) {
                $first_plugins = array_slice($plugin_order, 0, $index_of_all - 1);
                $last_plugins = array_slice($plugin_order, $index_of_all);
                $middle_plugins = array_diff($plugins, $first_plugins, $last_plugins);
                $plugins = array_merge($first_plugins, $middle_plugins, $last_plugins);
            } else {
                $unordered_plugins = array_diff($plugins, $plugin_order);
                $plugins = array_merge($plugin_order, $unordered_plugins);
            }
        }
        
        return $plugins;
        
    }
    
    public function init() {

        $this->load_controllers();
        $this->load_libs();
        $this->load_models();
        $this->load_settings();
        $this->load_functions();
		$this->register_shortcodes();
    }
    
    public function filter_post_link($permalink, $post) {
        if (substr($post->post_type, 0, 4) == 'mvc_') {
            $model_name = substr($post->post_type, 4);
            $controller = MvcInflector::tableize($model_name);
            $model_name = MvcInflector::camelize($model_name);
            $model = MvcModelRegistry::get_model($model_name);
            $object = $model->find_one_by_post_id($post->ID);
            if ($object) {
                $url = MvcRouter::public_url(array('object' => $object));
                if ($url) {
                    return $url;
                }
            }
        }
        return $permalink;
    }
    
    public function register_widgets() {
        foreach ($this->plugin_app_paths as $plugin_app_path) {
            $directory = $plugin_app_path.'widgets/';
            $widget_filenames = $this->file_includer->require_php_files_in_directory($directory);
  
            $path_segments_to_remove = array(WP_CONTENT_DIR, '/plugins/', '/app/');
            $plugin = str_replace($path_segments_to_remove, '', $plugin_app_path);

            foreach ($widget_filenames as $widget_file) {
                $widget_name = str_replace('.php', '', $widget_file);
                $widget_class = MvcInflector::camelize($plugin).'_'.MvcInflector::camelize($widget_name);
                register_widget($widget_class);
            }
        }
    }
    
    protected function load_controllers() {
    
        foreach ($this->plugin_app_paths as $plugin_app_path) {
        
            $admin_controller_filenames = $this->file_includer->require_php_files_in_directory($plugin_app_path.'controllers/admin/');
            $public_controller_filenames = $this->file_includer->require_php_files_in_directory($plugin_app_path.'controllers/');
            
            foreach ($admin_controller_filenames as $filename) {
                if (preg_match('/admin_([^\/]+)_controller\.php/', $filename, $match)) {
                    $controller_name = $match[1];
                    $this->admin_controller_names[] = $controller_name;
                    $capabilities = MvcConfiguration::get('admin_controller_capabilities');
                    if (empty($capabilities) || !isset($capabilities[$controller_name])) {
                        $capabilities = array($controller_name => 'administrator');
                    }
                    $this->admin_controller_capabilities[$controller_name] = $capabilities[$controller_name];
                }
            }
            
            foreach ($public_controller_filenames as $filename) {
                if (preg_match('/([^\/]+)_controller\.php/', $filename, $match)) {
                    $this->public_controller_names[] = $match[1];
                }
            }
        
        }
        
    }
    
    protected function load_libs() {
        
        foreach ($this->plugin_app_paths as $plugin_app_path) {
        
            $this->file_includer->require_php_files_in_directory($plugin_app_path.'libs/');
            
        }
        
    }
    
    protected function load_models() {
        
        $models = array();
        
        foreach ($this->plugin_app_paths as $plugin_app_path) {
        
            $model_filenames = $this->file_includer->require_php_files_in_directory($plugin_app_path.'models/');
            
            foreach ($model_filenames as $filename) {
                $models[] = MvcInflector::class_name_from_filename($filename);
            }
        
        }
        
        $this->model_names = array();
        
        foreach ($models as $model) {
            $this->model_names[] = $model;
            $model_class = MvcInflector::camelize($model);
            $model_instance = new $model_class();
            MvcModelRegistry::add_model($model, $model_instance);
        }
        
    }
    
    protected function load_settings() {
        
        $settings_names = array();
        
        foreach ($this->plugin_app_paths as $plugin_app_path) {
        
            $settings_filenames = $this->file_includer->require_php_files_in_directory($plugin_app_path.'settings/');
            
            foreach ($settings_filenames as $filename) {
                $settings_names[] = MvcInflector::class_name_from_filename($filename);
            }
        
        }
        
        $this->settings_names = $settings_names;
        
    }
    
    protected function load_functions() {
        
        foreach ($this->plugin_app_paths as $plugin_app_path) {
        
            $this->file_includer->require_php_files_in_directory($plugin_app_path.'functions/');
            
        }
    
    }

	protected function register_shortcodes() {

		$shortcodes = MvcConfiguration::get('ShortCodes');
		if (empty($shortcodes) || !is_array($shortcodes))
			return;

		foreach ($shortcodes as $shortcode => $options){
			add_shortcode($shortcode, array($this, 'dispatch_shortcode'));
		}
	}

	public function dispatch_shortcode($atts, $content, $shortcode) {
		$shortcodes = MvcConfiguration::get('ShortCodes');
		if (isset($shortcodes[$shortcode])) {

			$default_config = array(
				// by default, only allow actions that start with sc_
				'allow_actions' => '/sc_\w*|shortcode/',
				// by default, deny actions that don't start with sc_
				'deny_actions' => '',
			);
			$defaults_options = array(
				'is_shortcode' => true,
				'shortcode' => $shortcode,
				'action' => 'shortcode',
				'content' => $content,
			);

			// get shortcodes config from MvcConfiguration
			$shortcode_config = $shortcodes[$shortcode];
			// merge with default shortcodes config
			$config = array_merge($default_config, $shortcode_config);

			// pick up controller and possibly action from options in config
			if (isset($config['options'])) {
				$configured_options = $config['options'];
			} else {
				// if no options section in config - assume whole config is an options section
				$configured_options = $config;
			}

			// merge config options with default options
			$options = array_merge($defaults_options, $configured_options);

			// merge shortcode parameter options
            if($atts) {
                $options = array_merge($options, $atts);
            }

			// check the action against allowed and denied
			$action = $options['action'];
			$allowed = $config['allow_actions'];
			$denied = $config['deny_actions'];
			if ($this->checkActionAgainstPattern($action, $allowed, true) === false) {
				$content = 'MvcError: Action "'.$action.'" is not allowed for shortcodes by configuration allow_actions:['.$allowed.']';
			} else if ($this->checkActionAgainstPattern($action, $denied, false) === true) {
				$content = 'MvcError: Action "'.$action.'" is denied for shortcodes by configuration deny_actions:['.$denied.']';
			} else {
				// passed allowed and denied - dispatch the action
				$content = $this->dispatcher->dispatch($options);
			}
		}
		return do_shortcode($content);
	}

	protected function checkActionAgainstPattern($action, $pattern, $defaultMatch) {
		if (empty($pattern)) {
			return $defaultMatch;
		}

		if (is_string($pattern)) {
			$pattern = array($pattern);
		}

		$patterns = $pattern;
		foreach ($patterns as $pattern) {
			if ($action === $pattern) {
				return true;
            } else if (preg_match('/'.$pattern.'/', $action) === 1) {
				return true;
			}
		}
		return false;
	}
}

?>
