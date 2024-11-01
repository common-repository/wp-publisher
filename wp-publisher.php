<?php
/*
Plugin Name: WP Publisher
Description: sync two WordPress site by one click
Version: 0.1.1
Plugin URI:
Author: Eyeta Co.,Ltd.
Author URI: http://www.eyeta.jp/wp
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: wp-publisher
Domain Path: /languages/
*/

/**
 *
 *
 * Created by PhpStorm.
 * Author: Eyeta Co.,Ltd.(http://www.eyeta.jp)
 *
 */


register_activation_hook( __FILE__, 'wp_publisher_activate' );
function wp_publisher_activate() {
	// プラグイン初期化

}

register_deactivation_hook( __FILE__, 'wp_publisher_deactivate' );
function wp_publisher_deactivate() {
	// プラグイン削除
}

class wp_publisher {

	protected $_plugin_dirname;
	protected $_plugin_url;
	protected $_plugin_path;

	protected $local_files;
	protected $local_files_fixed;
	protected $remote_files;



	public function __construct() {
		// 初期パス等セット
		$this->init();

		// フックセット等
		add_action("init", array(&$this, "init_action"));
		add_action('wp_ajax_wp_publisher', array(&$this, "ftp_sync_callback"));
		add_action('wp_ajax_nopriv_wp_publisher_push', array(&$this,'wp_publisher_push'));
//		add_action('wp_ajax_wp_publishering', array(&$this, "ftp_syncing_callback"));

		add_action( 'plugins_loaded', array(&$this, 'load_textdomain'), 11 );


		// 管理画面
		add_action('admin_menu', array(&$this, "admin_menu"));
	}

	/**
	 * 管理画面追加
	 */
	function admin_menu () { // $page_title, $menu_title, $capability, $menu_slug, $function = ''
		include_once($this->get_plugin_path() . "/wp-publisher-admin.php");
		add_options_page('WP Publisher Option', 'WP Publisher', 'administrator', 'wp-publisher-options', "wp_publisher_options");
		add_action('admin_footer', 'wp_publisher_javascript');
	}


	function wp_publisher_push() {
		//test for secret
		$secret = $this->getSecret();
		if (stripslashes($_REQUEST['secret']) != $secret) {
			die(__("You don't know me"));
		}

	//	echo $sql = gzinflate($_POST['sql']);
		$sql = stripslashes($_POST['sql']);
		if ($sql && preg_match('|^/\* Dump of database |', $sql)) {

			//backup current DB
			//dbs_makeBackup();

			//store options
			$optionCache = $this->cacheOptions();

			//load posted data
			$this->loadSql($sql);

			//clear object cache
			wp_cache_flush();

			// テーブル一覧
			if(($all_tables = $this->get_tables()) === false) {
				echo __('Error: invalid SQL dump', "wp-publisher");
				exit;
			}

			$this->wpdb_replacer(get_option("siteurl"), $optionCache["siteurl"], $all_tables);

			//reinstate options
			$this->restoreOptions($optionCache);



			echo 'OK';
		} else {
			echo __('Error: invalid SQL dump', "wp-publisher");
		}
		exit;
	}

	function get_tables() {
		global $wpdb;

		$all_tables = array();
		$all_tables_mysql = $wpdb->get_results("SHOW TABLES", ARRAY_N);

		foreach($all_tables_mysql as $key => $row) {
			$all_tables[] = $row[0];
		}


		return $all_tables;
	}


	/**
	 * @return array key-value pairs of selected current WordPress options
	 */
	function cacheOptions() {
		//persist these options
		$defaultOptions = array('siteurl', 'home', "wp_publisher_outlandish_sync_secret", "wp_publisher_host", "wp_publisher_user", "wp_publisher_pass", "wp_publisher_port", "wp_publisher_active_mode", "wp_publisher_remote_wp_content_dir", "wp_publisher_token");
		$persistOptions = apply_filters('wp_publisher_persist_options', $defaultOptions);

		$optionCache = array();
		foreach ($persistOptions as $name) {
			$optionCache[$name] = get_option($name);
		}
		return $optionCache;
	}

	/**
	 * @param array $optionCache key-value pairs of options to restore
	 */
	function restoreOptions($optionCache) {
		foreach ($optionCache as $name => $value) {
			update_option($name, $value);
		}
	}

	function loadSql($sql) {
		$sql = preg_replace("|/\*.+\*/\n|", "", $sql);
		$queries = explode(";\n", $sql);
		foreach ($queries as $query) {
			if (!trim($query)) continue;
			if (mysql_query($query) === false) {
				return false;
			}
		}

		return true;
	}

	// sync function
	function ftp_sync_callback($data){
		if(!current_user_can(10)) {
			die;
		}

		set_time_limit(3600); // up the server script finish time limit

		$ftpUser = get_option('wp_publisher_user');
		$ftpHost = get_option('wp_publisher_host');
		$ftpPass = get_option('wp_publisher_pass');
		$ftpPort = get_option('wp_publisher_port');
		$activeMode = get_option('wp_publisher_active_mode');
		if($activeMode == 'active'){$activeMode = true;}
		else{$activeMode = false;}

		// local dirs
		$local_media_dir = ABSPATH . 'wp-content/uploads';
		$local_theme_dir = ABSPATH . 'wp-content/themes';
		$local_plugin_dir = ABSPATH . 'wp-content/plugins';

		// get current theme folder name
//		$current_theme = explode('/', $local_theme_dir);
//		$num_items = count($current_theme);
//		$current_theme_folder = $current_theme[$num_items - 1];

		// remote dirs - remove trailing slash for consistency
		$remote_theme_dir = rtrim(get_option('wp_publisher_remote_wp_content_dir'), "/") . '/themes/';
		$remote_media_dir = rtrim(get_option('wp_publisher_remote_wp_content_dir'), "/") . '/uploads';
		$remote_plugin_dir = rtrim(get_option('wp_publisher_remote_wp_content_dir'), "/") . '/plugins';

		// start session
		session_start();

		$step = htmlentities($_POST['step']);

		if($step > 200) {
			$name = "Plugin";
			$local_dir = $local_plugin_dir;
			$remote_dir = $remote_plugin_dir;
		} elseif($step > 100) {
			$name = "Media";
			$local_dir = $local_media_dir;
			$remote_dir = $remote_media_dir;
			// メディアフォルダチェック
			if(!is_dir($local_media_dir)) {
				$step = 110;
			}
		} else {
			$name = "Theme";
			$local_dir = $local_theme_dir;
			$remote_dir = $remote_theme_dir;
		}

		switch($step){

			case 1:
			case 101:
			case 201:

				$html = "<p><strong>" . $name . __(" Uploading", "wp-publisher") . "</strong></p>";
				$step++;
				break;

			case 2:
			case 102:
			case 202:
				$result = $this->get_ftp_connection($ftpHost, $ftpPort, $ftpUser, $ftpPass, $activeMode, true);
				$html = $result[1];
				if($result[0]){$step++;}
				else{$step = false;}
				break;

			case 3:
			case 103:
			case 203:
				$ftp = $this->get_ftp_connection($ftpHost, $ftpPort, $ftpUser, $ftpPass, $activeMode, false);
				$verified = $this->verify_remote_dir($ftp, $remote_dir);
				if($verified){
					$html = "OK<br/>";
					$step++;
				}else{
					$html = "<br/>ERROR: <strong>$remote_dir</strong>" . __(" does not exist on the server", "wp-publisher") . "<br/>";
					$step = false;
				}
				break;
			case 4:
			case 104:
			case 204:
				$html = $this->scan_local_files($local_dir . "/");
				$step++;
				break;
			case 5:
			case 105:
			case 205:
				$ftp = $this->get_ftp_connection($ftpHost, $ftpPort, $ftpUser, $ftpPass, $activeMode, false);
				$this->scan_remote_files($ftp, $remote_dir);
				$ftp->ftp_quit();
				$step++;
				break;
			case 6:
			case 106:
			case 206:
				$html = $this->compare_files($local_dir, $remote_dir);
				$step++;
				break;
			case 7:
			case 107:
			case 207:
				$local_files = $_SESSION['wp_publisher_local_files_to_upload'];
				if($local_files){
					$total = 0;
					foreach($local_files as $file){
						$total += filesize(str_replace('\\', '/', $file));
					}
					$html = "Uploading " . ceil(count($local_files)) . " files (" . round(($total/1024)/1024, 2) . " MB)...
						<span id='upload'></span>
						";
					$step++;
				}else{
					$html = __("No local files to upload.", "wp-publisher") . "<br/>";
					$step+=2;
				}
				break;

			case 8:
			case 108:
			case 208:
				$local_files = $_SESSION['wp_publisher_local_files_to_upload'];
				$ftp = $this->get_ftp_connection($ftpHost, $ftpPort, $ftpUser, $ftpPass, $activeMode, false);
				$html = $this->upload_files($ftp, $remote_dir, $local_dir, $local_files, $activeMode, $ftpPort);
				$ftp->ftp_quit();
				$html = "OK<br/>";
				$step++;
				break;

			case 9:
			case 109:
			case 209:
				$html = "<script type='text/javascript'>jQuery('#upload').fadeOut('fast');</script>";
				unset($_SESSION["wp_publisher_local_files_to_upload"]);
				unset($_SESSION["wp_publisher_remove_files_to_download"]);
				unset($_SESSION["wp_publisher_remove_files_to_delete"]);
				unset($_SESSION["wp_publisher_uploading"]);
				unset($_SESSION["wp_publisher_local_files"]);
				unset($_SESSION["wp_publisher_remote_files"]);
				$step++;
				break;

			case 301:
				// データベース処理
				//get SQL data
				ob_start();
				$this->mysqldump();
				$sql = ob_get_clean();

				try {
					//send post request with secret and SQL data
					$wp_publisher_token = base64_decode(get_option('wp_publisher_token'));
					@list($secret, $url) = explode(' ', $wp_publisher_token);

					$result = $this->post($url, 'wp_publisher_push', array(
						'secret' => $secret,
						'sql' => $sql
					));
					if ($result == 'You don\'t know me') {
						$html = __('Invalid site token', "wp-publisher");
					} elseif ($result == '0') {
						$html = __('Upload failed. Is the plugin activated on the remote server?', "wp-publisher");
					} elseif ($result == 'OK') {
						$html = __('Database synced successfully', "wp-publisher") ;
					} else {
						$html = __('Something may be wrong', "wp-publisher");
					}
				} catch (RuntimeException $ex) {
					$html = __('Remote site not accessible', "wp-publisher") . ' (HTTP ' . $ex->getCode() . ')';
				}

				if($html == "") {
					$html = "Database Sync OK<br/>";
				}

				$step = false;
				break;
			default:
				if($step > 200) {
					$step = 301;
				} elseif($step > 100) {
					$step = 201;
				} else {
					$step= 101;
				}
				break;
		}

		echo json_encode(array('step' => $step, 'html' => $html));

		die(); // this is required to return a proper result
	}

	/**
	 * @param $url string Remote site wpurl base
	 * @param $action string dbs_pull or dbs_push
	 * @param $params array POST parameters
	 * @return string The returned content
	 * @throws RuntimeException
	 */
	function post($url, $action, $params) {
		$remote = $url . '/wp-admin/admin-ajax.php?action=' . $action;
		error_log($remote);
		$ch = curl_init($remote);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
		$result = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($code != 200) {
			throw new RuntimeException('HTTP Error', $code);
		}
		return $result;
	}

	/**
	 * @param $table string Table name
	 * @return string SQL
	 */
	function mysqldump_table_structure($table) {
		echo "/* Table structure for table `$table` */\n\n";
		echo "DROP TABLE IF EXISTS `$table`;\n\n";

		$sql = "SHOW CREATE TABLE `$table`; ";
		$result = mysql_query($sql);
		if ($result) {
			if ($row = mysql_fetch_assoc($result)) {
				echo $row['Create Table'] . ";\n\n";
			}
		}
		mysql_free_result($result);
	}

	/**
	 * @param $table string Table name
	 * @return string SQL
	 */
	function mysqldump_table_data($table) {
		$sql = "SELECT * FROM `$table`;";
		$result = mysql_query($sql);

		echo '';
		if ($result) {
			$num_rows = mysql_num_rows($result);
			$num_fields = mysql_num_fields($result);
			if ($num_rows > 0) {
				echo "/* dumping data for table `$table` */\n";
				$field_type = array();
				$i = 0;
				while ($i < $num_fields) {
					$meta = mysql_fetch_field($result, $i);
					array_push($field_type, $meta->type);
					$i++;
				}
				$maxInsertSize = 100000;
				$index = 0;
				$statementSql = '';
				while ($row = mysql_fetch_row($result)) {
					if (!$statementSql) $statementSql .= "INSERT INTO `$table` VALUES\n";
					$statementSql .= "(";
					for ($i = 0; $i < $num_fields; $i++) {
						if (is_null($row[$i]))
							$statementSql .= "null";
						else {
							switch ($field_type[$i]) {
								case 'int':
									$statementSql .= $row[$i];
									break;
								case 'string':
								case 'blob' :
								default:
									$statementSql .= "'" . mysql_real_escape_string($row[$i]) . "'";

							}
						}
						if ($i < $num_fields - 1)
							$statementSql .= ",";
					}
					$statementSql .= ")";

					if (strlen($statementSql) > $maxInsertSize || $index == $num_rows - 1) {
						echo $statementSql.";\n";
						$statementSql = '';
					} else {
						$statementSql .= ",\n";
					}

					$index++;
				}
			}
		}
		mysql_free_result($result);
		echo "\n";
	}

	/**
	 * Dump the current MYSQL table.
	 * Original code (c)2006 Huang Kai <hkai@atutility.com>
	 */
	function mysqldump() {
		$sql = "SHOW TABLES;";
		$result = mysql_query($sql);
		echo '/* Dump of database '.DB_NAME.' on '.$_SERVER['HTTP_HOST'].' at '.date('Y-m-d H:i:s')." */\n\n";
		while ($row = mysql_fetch_row($result)) {
			echo $this->mysqldump_table_structure($row[0]);
			echo $this->mysqldump_table_data($row[0]);
		}
		mysql_free_result($result);
	}

	function getSecret() {
		$key = get_option('wp_publisher_outlandish_sync_secret');
		if (!$key) {
			$key = '';
			$length = 16;
			while ($length--) {
				$key .= chr(mt_rand(33, 126));
			}
			update_option('wp_publisher_outlandish_sync_secret', $key);
		}

		return $key;
	}

	/**
	 * @return string Encoded secret+URL token
	 */
	function getToken() {
		return trim(base64_encode($this->getSecret() . ' ' . get_bloginfo('wpurl')), '=');
	}



	/**
	 * ファイルアップロード
	 *
	 * @param $ftp
	 * @param $remoteRoot
	 * @param $localRoot
	 * @param $uploadFiles
	 * @param $active
	 * @return string
	 */
	function upload_files($ftp, $remoteRoot, $localRoot, $uploadFiles, $active, $ftpPort = 21){
		$html = "";

		// make root directory if not there
		if(!$ftp->ftp_chdir($remoteRoot)){
			$ftp->ftp_mkdir($remoteRoot);
		}

		// change dir to root to make the new directories from
		$ftp->ftp_chdir($remoteRoot);

	 	// correct slashes in localRoot
		$local_dir_fixed = str_replace('/', '\\', $localRoot);

		// iterate through files and upload
		foreach($uploadFiles as $file) {

			// make remote file path from local file
			$remote_dir = str_replace('\\', '/', str_replace($local_dir_fixed, $remoteRoot, $file));

			$dir = '';
			$parts = explode('/', $remote_dir);
			if($parts){
				foreach($parts as $part){
					if($part != end($parts)){
						$dir .= '/' . $part;
						// make root directory if not there
						if(!$ftp->ftp_chdir($dir)){
							$ftp->ftp_mkdir($dir);

						}
						$ftp->ftp_chdir($dir);
					}
				}
			}

			// change directory and upload file
			$ftp->ftp_chdir(dirname($remote_dir));
			$_SESSION["wp_publisher_uploading"] = str_replace('\\', '/', $file);
			$ftp->ftp_put(basename($remote_dir), str_replace('\\', '/', $file), FTP_BINARY);
		}

		// switch modes
		//ftp_pasv($ftp, !$active);

		$html .= "OK<br/>";
		return $html;
	}

	/**
	 * アップロード、削除対象ファイル抽出
	 *
	 * @param $local_dir
	 * @param $remote_dir
	 * @return string
	 */
	function compare_files($local_dir, $remote_dir){

		// get saved files
		$local_files = $_SESSION['wp_publisher_local_files'];
		$remote_files = $_SESSION['wp_publisher_remote_files'];
		$local_files_to_upload = array();
		$remote_files_to_delete = array();

		// get timezone offset
		//$timezone_offset = $_SESSION['timezone_offset'];

		$html .= "OK<br/>";

		// Find out newer or missing files to upload
		if($local_files){
			foreach($local_files as $file => $local_mod) {

				// change filenames to check against each other
				$local_fixed = str_replace('\\', '/', str_replace(str_replace('/', '\\', $local_dir), $remote_dir, $file));
				$local_files_to_upload[] = $file;
			}
			$_SESSION['wp_publisher_local_files_to_upload'] = $local_files_to_upload;
		}

		// Find out newer or missing files to download
		if($remote_files){
			foreach ($remote_files as $file => $remote_mod) {

				//change filenames to check against each other
				$remote_fixed = str_replace('/', '\\', str_replace($remote_dir . '/', str_replace('/', '\\', $local_dir) . '\\', $file));
				if(!isset($local_files[$remote_fixed])){
					$remote_files_to_delete[] = $file;
				}
			}
			$_SESSION['wp_publisher_remote_files_to_delete'] = $remote_files_to_delete;
		}

		return $html;
	}


	/**
	 * リモートファイル検索
	 *
	 * @param $ftp
	 * @param $remoteRoot
	 * @return string
	 */
	function scan_remote_files($ftp, $remoteRoot){

		$list = $ftp->ftp_rawlist($remoteRoot);

			$anzlist = count($list);
			$i = 0;
			while ($i < $anzlist) {
					$split = preg_split("/[\s]+/", $list[$i], 9, PREG_SPLIT_NO_EMPTY);
					$itemname = $split[8];
					$path = "$remoteRoot/$itemname";
					if(substr($itemname,0,1) != "."){
						if(substr($list[$i],0,1) === "d"){
							$this->scan_remote_files($ftp, $path);
						}else if(strlen($itemname) > 2){
							$this->remote_files[$path] = $ftp->ftp_mdtm($path);
						}
				}
					$i++;
			}

		if(empty($this->remote_files)){
			$html = __("No remote files found!", "wp-publisher") . "<br/>";
		}else{
			$_SESSION['wp_publisher_remote_files'] = $this->remote_files;
			$html = count($this->remote_files) . __(" remote files found!", "wp-publisher") . "<br/>";
		}

		return $html;
	}



	/**
	 * FTP接続取得
	 *
	 * @param $host
	 * @param $port
	 * @param $user
	 * @param $pass
	 * @param $active
	 * @param $echo
	 * @return array|resource
	 */
	function get_ftp_connection($host, $port, $user, $pass, $active, $echo){

		if($port == "21"){$port = 21;}
		else{$port == 22;}

		$result = array();

		// connect
		$ftp = new ftp(false);
		$rsl = $ftp->ftp_connect($host, $port);
		if($rsl){
			$result[0] = 1;
			$result[1] = __("Host Connection OK... ", "wp-publisher");
		}
		else{
			$result[0] = 0;
			$result[1] = __("Host Connection Failed!... ", "wp-publisher");
			return $result;
		}

		// login
		if($ftp->ftp_login($user, $pass)){
			$result[1] .= __("FTP Login... OK", "wp-publisher") . "<br/>";
		}
		else{
			$result[0] = 0;
			$result[1] .= __("FTP Login... Failed!", "wp-publisher") . "<br/>";
			return $result;
		}

		// switch modes
//		ftp_pasv($ftp, !$active);

		if($echo){
			$ftp->ftp_quit();
			return $result;
		}
		else{return $ftp;}
	}



	/**
	 * ローカルファイルチェック
	 *
	 * @param $local_root
	 * @return string
	 */
	function scan_local_files($local_root){

		$this->local_files = $this->scanLocal($local_root); //Scan local files.

		if(empty($this->local_files)){
			$html = __("No local files found!", "wp-publisher") . "<br/>";
		}else{
			$_SESSION['wp_publisher_local_files'] = $this->local_files; // save for later
			$html = count($this->local_files) . " local files found!<br/>";
		}

		return $html;
	}

	/**
	 * ローカルファイルリスト
	 *
	 * @param $dir
	 * @return mixed
	 */
	function scanLocal($dir) {
		global $local_files;

		if(is_dir($dir)){

			// load helper class
			$fileinfos = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator($dir)
			);

			foreach($fileinfos as $pathname => $fileinfo) {
					if (!$fileinfo->isFile()) continue;
					$local_files[$pathname] = filemtime($pathname);
			}

			// fix local directory slashes
			foreach ($local_files as $path => $mod) {
				$path_fixed = str_replace('/', '\\', $path);

				// exclude all files and folders that start with '.'
				if(!strpos($path_fixed,'\\.') !== false){
					$local_files_fixed[$path_fixed] = $mod;
				}
			}
		}

		return $local_files_fixed;
	}

	/**
	 * FTPリモートファイル存在チェック
	 *
	 * @param $ftpUser
	 * @param $ftpPass
	 * @param $ftpHost
	 * @param $ftpPort
	 * @param $remoteRoot
	 * @return bool
	 */
	function verify_remote_dir($ftp, $remoteRoot){

		if($ftp->ftp_chdir(dirname($remoteRoot))) {
			if($ftp->ftp_chdir($remoteRoot)){
				return true;
			}else{
				// dir作成
				$ftp->ftp_mkdir($remoteRoot);
				return true;
			}
		} else {
			return false;
		}
	}


	/*
	 * initフック
	 */
	function init_action() {
		// 各種フックセット


	}

	/*
	 * 初期化
	**/
	function init() {

		$array_tmp = explode(DIRECTORY_SEPARATOR, dirname(__FILE__));
		$this->_plugin_dirname = $array_tmp[count($array_tmp)-1];
		$this->_plugin_url = '/'. PLUGINDIR . '/' . $this->_plugin_dirname;
		$this->_plugin_path = dirname(__FILE__);

		$this->local_files = array();
		$this->local_files_fixed = array();
		$this->remote_files = array();

		require_once $this->_plugin_path . "/ftp-class.php";

	}

	function get_plugin_url() {
		return $this->_plugin_url;
	}

	function get_plugin_dirname() {
		return $this->_plugin_dirname;
	}

	function get_plugin_path() {
		return $this->_plugin_path;
	}


	/**
	  * Walk and array replacing one element for another. ( NOT USED ANY MORE )
	  *
	  * @param string $find    The string we want to replace.
	  * @param string $replace What we'll be replacing it with.
	  * @param array $data    Used to pass any subordinate arrays back to the
	  * function for searching.
	  *
	  * @return array    The original array with the replacements made.
	  */
	 function recursive_array_replace( $find, $replace, $data ) {
	     if ( is_array( $data ) ) {
	         foreach ( $data as $key => $value ) {
	             if ( is_array( $value ) ) {
	                 $this->recursive_array_replace( $find, $replace, $data[ $key ] );
	             } else {
	                 // have to check if it's string to ensure no switching to string for booleans/numbers/nulls - don't need any nasty conversions
	                 if ( is_string( $value ) )
	 					$data[ $key ] = str_replace( $find, $replace, $value );
	             }
	         }
	     } else {
	         if ( is_string( $data ) )
	 			$data = str_replace( $find, $replace, $data );
	     }
	 }


	 /**
	  * Take a serialised array and unserialise it replacing elements as needed and
	  * unserialising any subordinate arrays and performing the replace on those too.
	  *
	  * @param string $from       String we're looking to replace.
	  * @param string $to         What we want it to be replaced with
	  * @param array  $data       Used to pass any subordinate arrays back to in.
	  * @param bool   $serialised Does the array passed via $data need serialising.
	  *
	  * @return array	The original array with all elements replaced as needed.
	  */
	 function recursive_unserialize_replace( $from = '', $to = '', $data = '', $serialised = false ) {

	 	// some unseriliased data cannot be re-serialised eg. SimpleXMLElements
	 	try {

	 		if ( is_string( $data ) && ( $unserialized = @unserialize( $data ) ) !== false ) {
	 			$data = $this->recursive_unserialize_replace( $from, $to, $unserialized, true );
	 		}

	 		elseif ( is_array( $data ) ) {
	 			$_tmp = array( );
	 			foreach ( $data as $key => $value ) {
	 				$_tmp[ $key ] = $this->recursive_unserialize_replace( $from, $to, $value, false );
	 			}

	 			$data = $_tmp;
	 			unset( $_tmp );
	 		}

	 		else {
	 			if ( is_string( $data ) )
	 				$data = str_replace( $from, $to, $data );
	 		}

	 		if ( $serialised )
	 			return serialize( $data );

	 	} catch( Exception $error ) {

	 	}

	 	return $data;
	 }


	 /**
	  * The main loop triggered in step 5. Up here to keep it out of the way of the
	  * HTML. This walks every table in the db that was selected in step 3 and then
	  * walks every row and column replacing all occurences of a string with another.
	  * We split large tables into 50,000 row blocks when dealing with them to save
	  * on memmory consumption.
	  *
	  * @param mysql  $connection The db connection object
	  * @param string $search     What we want to replace
	  * @param string $replace    What we want to replace it with.
	  * @param array  $tables     The tables we want to look at.
	  *
	  * @return array    Collection of information gathered during the run.
	  */
	 function wpdb_replacer($search = '', $replace = '', $tables = array( ) ) {
	// 	global $guid, $exclude_cols;
		 global $wpdb;

	 	$report = array( 'tables' => 0,
	 					 'rows' => 0,
	 					 'change' => 0,
	 					 'updates' => 0,
	 					 'start' => microtime(),
	 					 'end' => microtime(),
	 					 'errors' => array(),
	 					 );

	 	if ( is_array( $tables ) && ! empty( $tables ) ) {
	 		foreach( $tables as $table ) {
	 			$report[ 'tables' ]++;

	 			$columns = array( );

	 			// Get a list of columns in this table
				$fields = $wpdb->get_results('DESCRIBE ' . $table, ARRAY_A);

	 			foreach( $fields as $column )
	 				$columns[ $column[ 'Field' ] ] = $column[ 'Key' ] == 'PRI' ? true : false;

	 			// Count the number of rows we have in the table if large we'll split into blocks, This is a mod from Simon Wheatley
				$rows_result = $wpdb->get_results('SELECT COUNT(*) FROM ' . $table, ARRAY_N);
	 //			$row_count = mysql_query( 'SELECT COUNT(*) FROM ' . $table, $connection );
	 			//$rows_result = mysql_fetch_array( $row_count );
	 			$row_count = $rows_result[0][0];
	 			if ( $row_count == 0 )
	 				continue;

	 			$page_size = 5000;
	 			$pages = ceil( $row_count / $page_size );

	 			for( $page = 0; $page < $pages; $page++ ) {

	 				$current_row = 0;
	 				$start = $page * $page_size;
	 				$end = $start + $page_size;
	 				// Grab the content of the table
					$data = $wpdb->get_results("SELECT * FROM $table LIMIT $start, $end", ARRAY_A);

	// 				if ( ! $data )
	// 					$report[ 'errors' ][] = mysql_error( );

	 				foreach ( $data as $row) {

	 					$report[ 'rows' ]++; // Increment the row counter
	 					$current_row++;

	 					$update_sql = array( );
	 					$where_sql = array( );
	 					$upd = false;

	 					foreach( $columns as $column => $primary_key ) {
	// 						if ( $guid == 1 && in_array( $column, $exclude_cols ) )
	// 							continue;

	 						$edited_data = $data_to_fix = $row[ $column ];

	 						// Run a search replace on the data that'll respect the serialisation.
	 						$edited_data = $this->recursive_unserialize_replace( $search, $replace, $data_to_fix );

	 						// Something was changed
	 						if ( $edited_data != $data_to_fix ) {
	 							$report[ 'change' ]++;
	 							$update_sql[] = $column . ' = "' . mysql_real_escape_string( $edited_data ) . '"';
	 							$upd = true;
	 						}

	 						if ( $primary_key )
	 							$where_sql[] = $column . ' = "' . mysql_real_escape_string( $data_to_fix ) . '"';
	 					}

	 					if ( $upd && ! empty( $where_sql ) ) {
	 						$sql = 'UPDATE ' . $table . ' SET ' . implode( ', ', $update_sql ) . ' WHERE ' . implode( ' AND ', array_filter( $where_sql ) );
							if($wpdb->query($sql) === false) {
								eyeta_log("Table update failure: " . $sql, "ERROR");
								$report[ 'errors' ][] = mysql_error( );
								return false;
							} else {
								$report[ 'updates' ]++;
							}

	 					} elseif ( $upd ) {
	 						$report[ 'errors' ][] = sprintf( '"%s" テーブルにプライマリキーが設定されておりません。設計が不十分なプラグインを利用している可能性があります。 %s.', $table, $current_row );
	 					}

	 				}
	 			}
	 		}

	 	}
	 	$report[ 'end' ] = microtime( );

	 	return $report;
	 }

	function load_textdomain( $locale = null ) {
		global $l10n;

		$domain = 'wp-publisher';

		if ( get_locale() == $locale ) {
			$locale = null;
		}

		if ( empty( $locale ) ) {
			if ( is_textdomain_loaded( $domain ) ) {
				return true;
			} else {
				return load_plugin_textdomain( $domain, false, $domain . '/languages' );
			}
		} else {
			$mo_orig = $l10n[$domain];
			unload_textdomain( $domain );

			$mofile = $domain . '-' . $locale . '.mo';
			$path = WP_PLUGIN_DIR . '/' . $domain . '/languages';

			if ( $loaded = load_textdomain( $domain, $path . '/'. $mofile ) ) {
				return $loaded;
			} else {
				$mofile = WP_LANG_DIR . '/plugins/' . $mofile;
				return load_textdomain( $domain, $mofile );
			}

			$l10n[$domain] = $mo_orig;
		}

		return false;
	}
}


global $wp_publisher;
$wp_publisher = new wp_publisher();




