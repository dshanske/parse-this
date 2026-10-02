=== Parse This ===
Contributors: dshanske
Tags: indieweb, microformats, jf2, json-ld, feeds
Stable tag: trunk
Requires at least: 6.2
Requires PHP: 7.4
Tested up to: 7.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Parse This turns URLs into structured jf2 data.

== Description ==

Parse This fetches a URL and turns it into [jf2](https://jf2.spec.indieweb.org/), a simple JSON format for posts, people and feeds. The result can be used for link previews, feed readers, replies and likes, and similar features.

It started from the parsing code in Press This, which was removed from WordPress, and has grown from there. It runs as a standalone plugin, and it is also bundled as a library in the Post Kinds and Yarns Microsub plugins.

It also runs on ClassicPress 2.x.

= What it parses =

* **Microformats2.** When a page is marked up with microformats, they are used first.
* **Other metadata.** If microformats don't yield content, it tries, in order: the page's WordPress REST API version (if the site advertises one), JSON-LD, a site-specific parser for YouTube, Instagram or Twitter, and finally Open Graph, Dublin Core and other meta tags.
* **Feeds.** RSS and Atom (through WordPress's SimplePie), JSON Feed 1 and 1.1, jf2 and mf2 JSON, and WordPress REST API post collections.
* **Feed discovery.** It can list the feeds a page offers.

= Using it from PHP =

    $parse = new ParseThis\Parser( 'https://example.com/a-post/' );
    $result = $parse->fetch();
    if ( ! is_wp_error( $result ) ) {
        $parse->parse( array( 'return' => 'single' ) );
        $jf2 = $parse->get();          // jf2 array.
        $mf2 = $parse->get( 'mf2' );   // Or the same result as mf2.
    }

`ParseThis\Parser::parse()` accepts these arguments:

* `return`: `single` for one item (default) or `feed` for a list of items.
* `follow`: fetch and parse external author pages. Default false.
* `limit`: maximum number of feed items. Default 150.
* `jsonld`: try JSON-LD. Default true.
* `html`: fall back to meta tags. Default true.
* `references`: move nested citations into `refs`, as the jf2 spec describes. Default true.
* `location`: flatten a nested location into `latitude`, `longitude` and `altitude` properties, with `location` as a plain string. Default false.
* `alternate`: use a `rel=alternate` jf2 or mf2 version of the page if it has one. Default false.

To list a page's feeds instead, use `( new ParseThis\Discovery() )->fetch( $url )`.

= REST API =

`GET /wp-json/parse-this/1.0/parse?url=https://example.com/`

* `url` (required): the URL to parse.
* `return`, `follow`, `references`, `location`: as for `parse()` above.
* `mf2`: return mf2 instead of jf2.
* `discovery`: list the URL's feeds instead of parsing it.

The endpoint is available to any logged-in user. Administrators can try it from **Tools > Parse This**.

= Filters =

* `pt_rewrite_secure`: the list of domains whose `http://` URLs are upgraded to `https://` before fetching.
* `parse_this_img_filters`: an image URL found in a page, after the built-in exclusions (ads, spinners, tracking pixels and so on). Return an empty string to drop it.

= Helper functions =

All classes and functions are in the `ParseThis` namespace.

* `ParseThis\mf2_to_jf2()` and `ParseThis\jf2_to_mf2()` convert between the two formats.
* `ParseThis\post_type_discovery()` returns the IndieWeb post type (note, article, reply, like, photo, ...) of a jf2 or mf2 entry.
* `ParseThis\jf2_references()` and `ParseThis\jf2_location()` apply the `references` and `location` transformations to any jf2 object.

Every function and class is documented in the source.

= Upgrading from 1.x =

Version 2.0.0 moved everything into the `ParseThis` namespace and dropped the `Parse_This_` prefix from class names (for example `Parse_This_MF2` is now `ParseThis\MF2`, and `Parse_This` is `ParseThis\Parser`). The old names that Post Kinds and Yarns use still work for now, as deprecated aliases:

* Classes: `Parse_This`, `Parse_This_Discovery`, `Parse_This_MF2`, `Parse_This_MF2_Utils` and `REST_Parse_This`.
* Functions: `mf2_to_jf2()`, `jf2_to_mf2()`, `post_type_discovery()`, `pt_load_domdocument()` and `seconds_to_iso8601()`.

Other old global names are no longer defined. Please switch to the namespaced names.

= Bundling Parse This in another plugin =

Copy the plugin into your plugin (for example under `lib/parse-this/`) and load it only if the standalone plugin hasn't already, from `plugins_loaded` at priority 10 or later:

    add_action( 'plugins_loaded', function () {
        if ( ! function_exists( 'parse_this_loader' ) ) {
            require_once __DIR__ . '/lib/parse-this/parse-this.php';
            parse_this_loader();
        }
    }, 11 );

The standalone plugin loads at priority 9, so when it is active it is used instead of your copy. Every function is also wrapped in `function_exists()` and classes are loaded by an autoloader, so if two copies do load, the first one wins.

Copies of Parse This 1.x bundled in other plugins keep working alongside 2.0: when the standalone plugin is active, their calls to the old names listed under "Upgrading from 1.x" are served by the standalone plugin.

== Frequently Asked Questions ==

= What is jf2? =

jf2 is a JSON format derived from microformats2, but simpler: single values aren't wrapped in arrays, and types drop the `h-` prefix. See the [jf2 specification](https://jf2.spec.indieweb.org/).

= Why does a page return only a title and a URL? =

That page has no microformats, JSON-LD or useful meta tags, or it blocks automated requests. The **Tools > Parse This** page shows exactly what was parsed for a URL.

= Which copy is used when several plugins bundle Parse This? =

The first one loaded. If the standalone plugin is active, it is that one. See "Bundling Parse This in another plugin" above.

= Does it work with ClassicPress? =

Yes. It is tested with ClassicPress 2.7 on PHP 7.4 to 8.3.

== Changelog ==

= 2.0.0 ( unreleased ) =
* Requires PHP 7.4 and WordPress 6.2 (or ClassicPress 2.x). Tested up to WordPress 7.1.
* Fix more than 30 bugs, many of them fatal errors on PHP 8. Affected: RSS feeds and dates, the WordPress REST API on plain-permalink sites, JSON-LD, the HTML meta-tag parser, microformats (h-resume, h-leg, h-geo, h-feed authors), JSON Feed, Twitter, YouTube and feed discovery.
* Fix `get()` on the parser so that keys other than `jf2` and `mf2` work.
* Parse HTTP Link headers that contain several links, or commas inside URLs.
* Move all classes and functions into the `ParseThis` namespace, with the `Parse_This_` prefix dropped from class names. The old names used by Post Kinds and Yarns remain as deprecated aliases; see "Upgrading from 1.x".
* Add `ParseThis\pt_remote_get()`, used for all remote requests.
* Use the `parse-this` text domain throughout.
* Document every function, class and filter in the source, following the WordPress documentation standards.
* Test against WordPress 6.2, the latest WordPress and ClassicPress 2.7.

= 1.0.1 ( 2021-04-02 ) =
* Remove SimplePie as a dependency as the latest version 1.5.6 is now bundled with WordPress as of 5.6.
* Remove MB polyfill due issues with PHP8.0 compatibility in favor of simpler solution.

= 1.0.0 ( 2020-12-15 ) =
* First Official Release. Prior to this point it was in a point release.
