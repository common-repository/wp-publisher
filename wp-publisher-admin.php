<?php
/**
 *
 *
 * Created by PhpStorm.
 * Author: Eyeta Co.,Ltd.(http://www.eyeta.jp)
 * 
 */

global $wp_publisher;

/**
 * 設定画面
 */
function wp_publisher_options() {
	global $wp_publisher;


	session_start(); // start session
	unset($_SESSION["wp_publisher_local_files_to_upload"]);
	unset($_SESSION["wp_publisher_remove_files_to_download"]);
	unset($_SESSION["wp_publisher_remove_files_to_delete"]);
	unset($_SESSION["wp_publisher_uploading"]);
	unset($_SESSION["wp_publisher_local_files"]);
	unset($_SESSION["wp_publisher_remote_files"]);


	if(isset($_POST['save'])){
		update_option('wp_publisher_host', $_POST['wp_publisher_host']);
		update_option('wp_publisher_user', $_POST['wp_publisher_user']);
		update_option('wp_publisher_pass', $_POST['wp_publisher_pass']);
		update_option('wp_publisher_port', $_POST['wp_publisher_port']);
		update_option('wp_publisher_active_mode', $_POST['wp_publisher_active_mode']);
		update_option('wp_publisher_remote_wp_content_dir', $_POST['wp_publisher_remote_wp_content_dir']);
		update_option('wp_publisher_token', $_POST['wp_publisher_token']);
	}

	$wp_publisher_active_mode = get_option('wp_publisher_active_mode');
	$ftp_sync_port = get_option('ftp_sync_port');
//	$ftp_sync_newer_by = get_option('ftp_sync_newer_by');

	//　登録完了チェック
	$fine = true;
	if(get_option('wp_publisher_host') == "") $fine = false;
	if(get_option('wp_publisher_user') == "") $fine = false;
	if(get_option('wp_publisher_pass') == "") $fine = false;
	if(get_option('wp_publisher_remote_wp_content_dir') == "") $fine = false;
	if(get_option('wp_publisher_token') == "") $fine = false;


	$html .= '<div class="wrap"><h2>WordPress Publisher <em style="font-size:14px;color:#ccc;">(version 0.1)</em></h2><hr/>';

	// terms and conditions
	if(!isset($_COOKIE['wp-publisher-terms-agreed'])){
		$html .= '<div id="wp-publisher-terms" style="margin-top:20px;">
					<div style="padding:20px;background:#fff;border-radius:5px;border:1px solid #ddd;">
						<h2>' .  __("Before you use WordPress Sync you must first agree to these terms and conditions:", "wp-publisher") . '</h2>
						<ol>
							<li>' .  __("You are using WordPress Sync at your own risk!", "wp-publisher") . '</li>
							<li>' .  __("You will create a backup of all your files before using WordPress Sync.", "wp-publisher") . '</li>
							<li>' .  __("WordPress Sync is not responsible for any lost, damaged, or overwritten files.", "wp-publisher") . '</li>
							<li>' .  __("By clicking the button below you are agreeing to all the terms and conditions listed above. Enjoy!", "wp-publisher") . '</li>
							</ol>
							<button id="wp-publisher-terms-agree" style="cursor:pointer;">' .  __("I agree!", "wp-publisher") . '</button>
						</div>
					</div>
					<div id="wp-publisher-main" style="display:none;">';
		}else{
			$html .= '<div id="wp-publisher-main">';
		}

		$html .= '<div style="float:left;width:55%;"><form action="" method="post">
			<table width="100%" cellpadding="10">
				<tbody>
					<tr>
						<td>
							<label>FTP Host: <em>(domain or IP)</em></label>
					 		<input style="width:100%;" type="text" name="wp_publisher_host" value="' . get_option('wp_publisher_host') . '" />
					 	</td>
					</tr>
					<tr>
					 	<td>
					 		<label>FTP User:</label>
					 		<input style="width:100%;" type="text" name="wp_publisher_user" value="' . get_option('wp_publisher_user') . '" />
					 	</td>
					</tr>
					<tr>
					 	<td>
					 		<label>FTP Pass:</label>
					 		<input style="width:100%;" type="password" name="wp_publisher_pass" value="' . get_option('wp_publisher_pass') . '" />
					 	</td>
					</tr>
					<tr>
						<td>
							<label>FTP Port: <em>(FTP = 21, SFTP = 22 *not currently supported)</em></label><br/>
							<select name="wp_publisher_port">
							    <option value="21"' . selected($wp_publisher_port, "21", false) . '>21</option>
							    _<!--<option value="22"' . selected($wp_publisher_port, "22", false) . '>22</option>-->
							</select>
					 	</td>
					</tr>
<!--					<tr>
					 	<td>
					 		<label>FTP Mode:</label><br/>
							<select name="wp_publisher_active_mode">
							    <option value="passive"' . selected($wp_publisher_active_mode, 'passive', false) . '>Passive</option>
							    <option value="active"' . selected($wp_publisher_active_mode, 'active', false) . '>Active</option>
							</select>
					 	</td>
					</tr>-->
					<tr>
					 	<td>
					 		<label>' .  __("Remote wp-content FTP Server Path:", "wp-publisher") . ' <em>(ie. /public_html/wp-content)</em></label>
					 		<input style="width:100%;" type="text" name="wp_publisher_remote_wp_content_dir" value="' . get_option('wp_publisher_remote_wp_content_dir') . '" />
					 	</td>
					</tr>
					<tr>
					 	<td>
					 		<label>' .  __("Target WordPress token:", "wp-publisher") . '</label>
					 		<input style="width:100%;" type="text" name="wp_publisher_token" value="' . get_option('wp_publisher_token') . '" />
					 	</td>
					</tr>
					<tr>
					 	<td>
					 		<label>' .  __("My WordPress token:", "wp-publisher") . '</label>
					 		<input type="text" style="width: 100%;" readonly value="' . $wp_publisher->getToken() . '" />
					 	</td>
					</tr>
					<tr>
					 	<td>
					 		<br/><input class="button button-primary" type="submit" name="save" value="' .  __("Save Settings", "wp-publisher") . '" />
					 	</td>
					</tr>
				</tbody>
			</table>
	 	</form></div>';

		$html .= '<div style="float:right;width:40%;margin-top:2em;"><table class="widefat" width="100%" cellpadding="10">
				<tbody>
					<tr>
						<td scope="row" align="left">
							<label style="font-size:1.5em;">' .  __("Ready to WP Upload?", "wp-publisher") . '</label>
						</td>
					</tr>
					<tr><td><div id="sync-status"></div></td></tr>
					<tr>
						<td scope="row" align="left">
							<div id="buttons">';
		if($fine) {
			$html .= '	<button id="wp-publisher-go" class="button">' .  __("Upload Start", "wp-publisher") . '</button>';
		}
	$html .= '	</div>
						 	<div id="loading-gif" style="display:none;">
						 		<img src="' . plugins_url() . '/wp-publisher/ajax-loader.gif" alt="loading" />
						 	</div>
					 	</td>
					 </tr>
				</tbody>
			</table>


	 		</div></div></div>';

	echo $html;
}


function wp_publisher_javascript() {
?>
	<script type="text/javascript">
		jQuery(document).ready(function($){

			// terms click cookie
			$('#wp-publisher-terms-agree').click(function(){
				var today = new Date();
				var expire = new Date(today.getTime() + (30 * 24 * 60 * 60 * 1000)); // in 30 days
				document.cookie = "wp-publisher-terms-agreed=1;expires=" + expire + ";";
				$('#wp-publisher-terms').hide();
				$('#wp-publisher-main').fadeIn();
			});

			// do sync on button click
			$("#wp-publisher-go").click(function(){
				// hide sync button
				$(this).parent('#buttons').hide();
				$('#loading-gif').show();
				// remove previous sync
				$('#sync-status').html('');
				do_wp_publisher(1);
			});

			function do_wp_publisher(step){
				var data = {
					action: 'wp_publisher',
					step: step
				};

				$.ajax({
					type: "post",
					url: ajaxurl,
					data: data,
					dataType: "json",
					success: function(response) {
						// append and call next step
						$('#sync-status').append(response.html);
						if(response.step){
							do_wp_publisher(response.step);
						}else{
							// show sync
							$('#loading-gif').hide();
							$('#buttons').fadeIn('fast');
						}
					},
					error: function(xhr, status, err) {

			            // append error
						$('#sync-status').append('<br/><br/><strong>AJAX ERROR:<br/>' + xhr.responseText + '</strong>');

						// show sync
						$('#loading-gif').hide();
						$('#buttons').fadeIn('fast');
			        }
				});
			}

		});

	</script>
<?php
}
