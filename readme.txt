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
* **Other metadata.** Microformats always come first. Other sources only fill in what microformats don't provide: a site-specific parser for YouTube or X (Twitter), then JSON-LD and Open Graph, Dublin Core and other meta tags. If there is still no content, the page's WordPress REST API version is fetched (when the site advertises one).
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
* `require_content`: whether a summary alone isn't enough, so the page's WordPress REST API version is fetched for full content. Default: true for feeds, false otherwise.
* `always_arrays`: always return `category`, `photo`, `video`, `audio`, `syndication`, `like-of`, `repost-of`, `bookmark-of` and `in-reply-to` as arrays, as Microsub does. Default false, which follows jf2: a single value is not wrapped in an array.
* `debug`: include the raw data each source was read from (`_meta`, `_jsonld`, `_json`, `_yt`, `_ombed`, `_rest`). Default false. Before 2.0.0 this was added whenever `WP_DEBUG` was on.

To list a page's feeds instead, use `( new ParseThis\Discovery() )->fetch( $url )`.

= Output format =

Results follow [jf2](https://jf2.spec.indieweb.org/), with a few deliberate differences:

* A feed's entries are in `items`, as in Microsub timelines, rather than the JF2 Feed profile's `children`.
* Nested citations are stored in `refs`, as XRay does, rather than jf2's `references`.
* Entries get a `post-type` property from [Post Type Discovery](https://www.w3.org/TR/post-type-discovery/).
* Reviews can be an h-review, or an h-entry with `review-of` (a URL, or a nested h-cite, h-card, h-event, h-item or h-product) and `rating`, `best` and `worst`, as proposed in [microformats/h-entry#32](https://github.com/microformats/h-entry/issues/32) and published by Post Kinds. Both, and an h-entry that is also an h-review, get the post type `review`.
* `author` is always a card (or a list of cards), never a plain string.
* Properties starting with an underscore (`_links`, `_rest`, ...) are internal or debugging data.

Values from fetched pages are sanitized: URL properties only contain `http` and `https` URLs, plain-text properties (`name`, `summary`, `category`, `content`'s `text`) have no HTML tags, and `content`'s `html` is limited to a safe set of tags. Escape values when you output them all the same, as with any remote data. Debugging data (`_jsonld`, `_meta`, ...) is not sanitized.

= REST API =

`GET /wp-json/parse-this/1.0/parse?url=https://example.com/`

* `url` (required): the URL to parse.
* `return`, `follow`, `references`, `location`, `require_content`, `always_arrays`, `debug`: as for `parse()` above.
* `mf2`: return mf2 instead of jf2.
* `discovery`: list the URL's feeds instead of parsing it.
* `nocache`: fetch the URL again instead of using a cached result.

Results are cached for 15 minutes per URL and set of parameters, so pasting the same link again doesn't fetch it again. Requests with `debug` are never cached.

The endpoint requires the `edit_posts` capability (Contributors and above); the `parse_this_rest_capability` filter changes it. Anyone who can use it can also try it from **Tools > Parse This**.

= Filters =

* `pt_rewrite_secure`: the list of domains whose `http://` URLs are upgraded to `https://` before fetching.
* `parse_this_img_filters`: an image URL found in a page, after the built-in exclusions (ads, spinners, tracking pixels and so on). Return an empty string to drop it.
* `parse_this_url_shorteners`: the hosts treated as link shorteners. Links to them in a summary are expanded to where they redirect; other links are left as they are, without a request.
* `parse_this_max_requests`: how many further requests one parse may make for followed author pages and short-link expansion. Default 10. Receives the URL.
* `parse_this_cache_lifetime`: how long REST endpoint results are cached, in seconds. Default 15 minutes. Return 0 to turn caching off. Receives the URL.
* `parse_this_rest_api_jf2_type`: the jf2 type for a post read through a site's WordPress REST API. Default `entry`. Receives the REST API post object, which includes its WordPress post type, and the site's REST API root URL.

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
* Remove the Instagram parser. Instagram stopped embedding the data it read; Instagram pages are now parsed from their Open Graph tags like any other page.
* Remove the `ifset()` helper in favour of PHP's `??` operator. It was only needed for PHP 5.6; it also added missing keys to the arrays it read.
* RSS and Atom items use `post-type`, like other sources, instead of `post_type`.
* Authors are always returned as cards. Add the `always_arrays` parse argument for Microsub-style arrays.
* Microformats always win: other sources (JSON-LD, meta tags, the REST API) only fill in missing properties, and likes, bookmarks and other responses keep their microformats even without content. Add the `require_content` parse argument.
* Microformats: keep every value of a property (for example several categories), parse nested citations without warnings, keep the type of h-review, h-product, h-resume, h-listing, h-recipe, h-item and h-leg, parse unrecognized h-* types, return a page's single top-level item (an h-feed with its URL) directly, and keep feed item author URLs as strings.
* Give JSON Feed items and authors, and posts read through the WordPress REST API, their jf2 types. Add the `parse_this_rest_api_jf2_type` filter.
* Keep JSON Feeds and REST API collections served as `application/json`, handle RSS items without enclosures on newer SimplePie, and no longer merge raw JSON into results.
* Use core's `fetch_feed()` for RSS and Atom, now that core's SimplePie is current.
* Performance: download feeds once rather than twice; only expand links in summaries from known link shorteners (filterable with `parse_this_url_shorteners`), which removes a request per link; read REST API tags from the embedded data instead of one request per post; request only the site details needed from a site's REST API index (177 bytes instead of about 580 KB); fetch each followed author page once per request; stop parsing feed items once the limit is reached; and fix REST API caching, which never worked for long URLs.
* Cache REST endpoint results for 15 minutes. Add the `nocache` parameter and the `parse_this_cache_lifetime` filter.
* Include raw source data (`_meta`, `_jsonld`, `_yt` and so on) only with the new `debug` argument, rather than whenever `WP_DEBUG` is on.
* Security: the REST endpoint and the Tools > Parse This page require `edit_posts` (filterable with `parse_this_rest_capability`); the endpoint's parameters are declared and validated; the debug page sends its nonce in a header instead of the URL; output from fetched pages is sanitized; one parse makes at most 10 further requests (`parse_this_max_requests`); plugin files exit when loaded outside WordPress; and the OPML class handles invalid input safely.
* Fix content HTML losing the text before its first tag, which cut the opening words from most notes.
* Return an error for HTTP error pages (`not_found`, `unauthorized`, `forbidden`, `http_error`) instead of parsing them as content; a 410 Gone page is still parsed, with `_code`.
* Microformats: read `follow-of`; read the ingredients, yield, duration, nutrition and instructions of recipes, more event properties, and the replies and likes of reviews; give events, reviews and recipes a `post-type`; keep names and ratings of "0"; and follow Post Type Discovery for entries with a name and no content (articles).
* Support reviews published as an h-entry with `review-of`, `rating`, `best` and `worst` (microformats/h-entry#32), as Post Kinds publishes them, and h-entry h-review; ratings are also kept on other entries (rated watches, reads and so on).
* Return `rel=author` authors as jf2 cards, and give results that only meta tags filled the type `entry`.
* Date posts read through the WordPress REST API from their GMT dates, so they are correct even without the site's timezone.
* Read YouTube pages in full (they exceed the 1 MB limit) and extract the player data reliably.
* Recognize x.com post URLs, and use the publish.x.com oEmbed endpoint.
* Fix YouTube feed discovery for `@handle` URLs and the video ID in parsed videos.
* Update the bundled php-mf2 (0.5.0) and masterminds/html5 (2.11.0) libraries.
* Remove polyfills for functions WordPress 6.2 already provides, and the deprecated `who` argument to `get_users()`.
* Use the `parse-this` text domain throughout.
* Document every function, class and filter in the source, following the WordPress documentation standards.
* Test against WordPress 6.2, the latest WordPress and ClassicPress 2.7.

= 1.0.1 ( 2021-04-02 ) =
* Remove SimplePie as a dependency as the latest version 1.5.6 is now bundled with WordPress as of 5.6.
* Remove MB polyfill due issues with PHP8.0 compatibility in favor of simpler solution.

= 1.0.0 ( 2020-12-15 ) =
* First Official Release. Prior to this point it was in a point release.
