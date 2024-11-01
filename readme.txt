=== WP Publisher ===
Contributors: Yuichiro ABE
Donate link:
Tags: sync, publish, deploy
Requires at least: 3.8
Tested up to: 3.9.1
Stable tag: 0.1.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

"Plug-in to Upload WordPress site Dev to Publish by one click".

== Description ==

Plug-in to synchronize the two WordPress

And synchronization of theme folder
And synchronization of the plug-ins folder
And synchronization of media
And conversion (the serialized data including) the host name in the database

Plug-in to be used, for example, when you publish WordPress from a development environment to a production environment

== Installation ==

1. Upload the entire `wp-publisher` folder to the `/wp-content/plugins/` directory in a production environment.
2. Upload the entire `wp-publisher` folder to the `/wp-content/plugins/` directory in a development environment
3. Activate the plugin through the 'Plugins' menu in WordPress.
4. on "Publishing environment", [Setting][WP Publisher] remenber "My WordPress token".
5. on "Development environment", [Setting][WP Publisher] input FTP Host, FTP User, FTP Passowrd, And "Publishing environment"'s token.(sorry FTP is pasv only)
6. click save setting
7. and click "Upload Start"

For basic usage, you can also have a look at the [plugin homepage](http://www.eyeta.jp/archives/997).

== Frequently asked questions ==

= A question that someone might have =

An answer to that question.

== Screenshots ==


== Changelog ==

0.1
 beta release.
0.1.1
 add detail of instation to readme.txt
0.1.2
 Correction readme.txt

== Upgrade notice ==
0.1.2
 Correction readme.txt
