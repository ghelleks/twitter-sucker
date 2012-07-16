<?php

/*
Plugin Name: Twitter Sucker
Plugin URI: http://github.com/ghelleks/twitter-sucker
Description:  Create posts from tweets in your <a href="http://twitter.com">Twitter</a> account. Based on Tim Beck's Twitter Digest (http://whalespine.org)
Version: 1.0
Author: Gunnar Hellekson <gunnar@hellekson.com>
Author URI: http://gunnar.hellekson.com/about
*/

// Copyright (c) 2009 - 2010 Tim Beck and Paul Wlodarczyk. All rights reserved.
// Copyright (c) 2012 Gunnar Hellekson.
//
// Released under the GPL license
// http://www.opensource.org/licenses/gpl-license.php
//
// This is an add-on for WordPress
// http://wordpress.org/
//
// **********************************************************************
// This program is distributed in the hope that it will be useful, but
// WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. 
// **********************************************************************
//
//=======================
// Defines

define('WS_FETCH_SPACE', 30); //X seconds between fetches 
define('WS_TWITTER_LIMIT', 200); //the most tweets you can pull from the Twitter REST API  
define('WS_API_USER_TIMELINE', 'http://api.twitter.com/1/statuses/user_timeline.json');
define('WS_STATUS_URL', 'https://twitter.com/###USERNAME###/statuses/###STATUS###');
define('WS_PROFILE_URL', 'http://twitter.com/###USERNAME###');
define('WS_HASHTAG_URL', 'http://search.twitter.com/search?q=###HASHTAG###');
define('WS_VERSION', '1.0');

define('WS_TD_TWITTER_ERROR', 1); // Something went wrong with twitter
define('WS_TD_TOO_SOON', 2); // rate limit exceeded
define('WS_TD_BAD_CREDENTIALS', 3); // bad username/password
define('WS_TD_POST_CREATED', 4); // post created or drafted
define('WS_TD_NO_TWEETS', 5); // No tweets to post
define('WS_TD_POST_SCHEDULED', 6); // Post created but not published until later
define('WS_TD_WRONG_DAY_OF_WEEK', 7); // not scheduled for this day of the week on a weekly poll
define('WS_TD_NOT_ENOUGH_TWEETS', 8); // not scheduled for this day of the week on a weekly poll

//========================
// Digest cron activation

register_activation_hook(__FILE__, 'ws_ts_activate_sucker');
register_deactivation_hook(__FILE__, 'ws_ts_deactivate_sucker');
add_action('ws_ts_digest_event', 'ws_ping_twitter');

function ws_ts_activate_sucker() {
	if (!wp_next_scheduled('ws_ts_digest_event')) {
		wp_schedule_event(time(), 'hourly', 'ws_ts_digest_event');
	}
}

function ws_ts_deactivate_sucker() {
  wp_clear_scheduled_hook('ws_ts_digest_event');
}

// Check Twitter to see if there are any new tweets that need to be published.
// null -> all good
// Return values:
// 1 -> Error w/ twitter
// 2 -> Too soon
// 3 -> Missing username/password
// 4 -> Post created and published now or put in draft (depending on status option)
// 5 -> No tweets to post
// 6 -> Post created but not published until later
// 7 -> Wrong day of week
// 8 -> not enough tweets

function ws_ping_twitter() {

	// get the last tweet we published
	$lastTweet = get_option('ws_ts_last_tweet'); 
	if (!$lastTweet) {
		$lastTweet = 0;
		update_option('ws_ts_last_tweet', $lastTweet);
	}

	// the number for the last tweet we're fetching now, which will be the $lastTweet next time around
	$newLastTweet = 0; 

	// the username we're grabbing tweets from
	$twitter_user = get_option("ws_ts_username");

	// Do we have a username and password?
	if (!$twitter_user) {
		return WS_TD_BAD_CREDENTIALS;;
	}

	// Has there been enough time since the last check? This avoids a race
	// condition which would produce a duplicate post.
	$last = get_option('ws_ts_last_check');
	if (time() - $last < WS_FETCH_SPACE) {
		return WS_TD_TOO_SOON;
	}

	update_option("ws_ts_last_check", time());

	// Get the maximum number of tweets
	$numtweets = get_option('ws_ts_num_tweets');

	// range check. Twitter limit is to 200
	if ($numtweets > WS_TWITTER_LIMIT) { 
		$numtweets = WS_TWITTER_LIMIT;
	}
  
	if ($numtweets == 0) {
		$numtweets = 20;  //default is 20 with no count argument, so fetch 20 if NULL
	}

	// get the last N tweets, since the last tweet
	$url = WS_API_USER_TIMELINE . "?screen_name=" . $twitter_user . "&count=". $numtweets ;

	if (get_option('ws_ts_includeRTs')) {
		$url .= '&include_rts=1';
	}
  
	if ($lastTweet > 0) {
		$url .= "&since_id=" . $lastTweet; 
	}

	// Fetch the tweets
	$tweets = ws_fetch_tweets($url);

	$tweet_content = array();

	// Go through the array and process any tweets from the desired time period 
	if (count($tweets) > 0) { 

		// remember this for later.
		$newLastTweet = $tweets[0]->id_str;

		// process the tweets
		foreach ($tweets as $tw_data) {

			// Are we dropping replies?
			if (get_option('ws_ts_drop_replies') && preg_match('/^@.*/', $tw_data->text)) {
				continue;
			}
      
			// All good, so format and add to the content
			$post_result = ws_create_post($tw_data->text, ws_format_tweet($twitter_user, $tw_data));

			if ($post_result == 1 /* Published in future */) {
				$retval = WS_TD_POST_PUBLISHED;
			} 
			else {
				// Published now or drafted.
				$retval = WS_TD_POST_CREATED;
			}
        
		}

		// Update the last tweet id
		update_option('ws_ts_last_tweet', $newLastTweet);
	}
	else {
		// no tweets from Twitter
		$retval = WS_TD_NO_TWEETS_TO_POST;
	}
	return $retval;
}

// This function creates the actual post and schedules it for publishing time
// at $pubtime, if the status option is set to 'publish'. Otherwise the post
// is put in 'draft' status.
// Return values:
// 0: published now
// 1: published in future
// 2: drafted
function ws_create_post($title, $content) {

  global $wpdb;
  $result = 0;
  
  // Are we putting this in draft or publishing (now or later)?
  if (get_option('ws_ts_status') == 'draft') {
    $status = 'draft';
    $result = 2;
  } else {
    $status='publish';
  }

  // Create the post
  $post_data = array(
                     'post_content' => $wpdb->escape($content),
                     'post_title' => $wpdb->escape($title),
                     'post_date' => $time,
                     'post_category' => array(get_option('ws_ts_category')),
                     'post_status' => $status,
                     'post_author' => $wpdb->escape(get_option('ws_ts_author')),
                     'post_excerpt' => $wpdb->escape($excerpt)
                     );
  
  // Insert post
  $post_id = wp_insert_post($post_data);
  add_post_meta($post_id, 'ws_tweeted', '1', true);

  // Make it a status update
  set_post_format($post_id, 'status');
  
  // Tag it
  wp_set_post_tags($post_id, get_option('ws_ts_post_tags'));

  return $result;
}

// Returns an html formatted $tweet.  This is almost directly borrowed from Twitter Tools
function ws_format_tweet($twitter_user, $tweet) {
  $output = ws_status_url($twitter_user, $tweet->id_str)."\n\n";
  //$output = $tweet->html;
  return $output;
}

function ws_status_url($username, $status) {
  return str_replace(
                     array('###USERNAME###', '###STATUS###'),
                     array($username, $status),
                     WS_STATUS_URL);
}

// Based on Alex King's implementation for the Twitter Tools plugin
function ws_fetch_tweets($url) {
  
  require_once(ABSPATH.WPINC.'/class-snoopy.php');
  $snoop = new Snoopy;
  $snoop->agent = 'Whalespine';
  $snoop->fetch($url);
  
  if (!strpos($snoop->response_code, '200')) {
     update_option('ws_ts_error', 'Error retrieving tweets. Make sure your username and password are correct.<br/> Status: '.$snoop->status.' <br/> '.$snoop->results);
     return false;
  }

  // Everything is ok
  update_option('ws_ts_error','');

  // To parse the json we use the build in function if we have it.
  // Note that Services_JSON isn't the fastest thing in the world and has
  // known to time out PHP on large json strings
  if (function_exists('json_decode')) {
    $tweets = json_decode($snoop->results);
  } else {
    // Process the result
    $json = new Services_JSON();
    $json->decode($snoop->results);    
  }
  
  return $tweets;
}

// Simply resets the 'ws_ts_last_tweet' option
function ws_ts_resetDatabase() {
  update_option('ws_ts_last_tweet',0);   
}

// The menu hook
function ws_menu_item() {
  if (current_user_can('manage_options')) {
    add_options_page(
                     __('Twitter Sucker Options', 'twitter-sucker')
                     , __('Twitter Sucker', 'twitter-sucker')
                     , 10
                     , basename(__FILE__)
                     , 'ws_options_form'
                     );
  }
}
add_action('admin_menu', 'ws_menu_item');


function ws_options_form() {

  // Check here if we are going to do the check now
  global $wpdb;

  // Reset the database if necessary
  if ($_POST["action"] == 'resetdb') {
     ws_ts_resetDatabase();
  }

  // Get some variables together
  $ping_message = "(This may take a while if there are tweets to process.)";
  $ping_style="color: black";
  
  // Ping if necessary, and show the correct message.
  if ($_POST["action"] == "ping") {
    switch(ws_ping_twitter(true)) {
    case WS_TD_TWITTER_ERROR:
      $pwuser = get_option('ws_ts_username');
      $ping_message = "Something went wrong with Twitter.  Check for an error message above. Current Twitter username is " . $pwuser. "." ;
      $ping_style = "color: red";
      break;
    case WS_TD_TOO_SOON:
      $ping_message = "To keep things sane, we're going to wait ".WS_FETCH_SPACE." seconds between pings.";
      $ping_style = "color: red";
      break;
    case WS_TD_BAD_CREDENTIALS:
      $ping_message = "Missing Twitter username and/or password";
      $ping_style = "color: red";
      break;
    case WS_TD_POST_CREATED:
      $ping_message = "Post has been ".(get_option('ws_ts_status'). 'ed.');
      $ping_style ="color: green";
      break;
    case WS_TD_POST_SCHEDULED: 
      $ping_message = "Post scheduled.";
      $ping_style = "color: green";
      break;
    case WS_TD_NO_TWEETS_TO_POST: 
      $ping_message = "No new tweets found.";
      break;
    case WS_TD_NO_TWEETS_MEET_CRITERIA: 
      $ping_message = "No tweets found that meet the criteria.";
      break;
    case WS_TD_NOT_ENOUGH_TWEETS:
      $ping_message = "There were not enough tweets to meet your minimum number.";
      break;
    }
  }
  
  // Get all the options together
  $categories = get_categories('hide_empty=0');
  $cat_options = '';
  foreach ($categories as $category) {
    if ($category->term_id == get_option('ws_ts_category')) {
      $selected = 'selected="selected"';
    }
    else {
      $selected = '';
    }
    $cat_options .= "\n\t<option value='$category->term_id' $selected>$category->name</option>";
  }
  
  $authors = get_users_of_blog();
  $author_options = '';
  foreach ($authors as $user) {
    $usero = new WP_User($user->user_id);
    $author = $usero->data;
    // Only list users who are allowed to publish
    if (! $usero->has_cap('publish_posts')) {
      continue;
    }
    if ($author->ID == get_option('ws_ts_author')) {
      $selected = 'selected="selected"';
    }
    else {
      $selected = '';
    }
    $author_options .= "\n\t<option value='$author->ID' $selected>$author->user_nicename</option>";
  }
  
  // Set up the options for the status. Just draft or publish for now.
  $status_options = '';
  if (get_option('ws_ts_status') == 'draft') {
    $status_options ='
       <option value="publish">'.__('Publish', 'twitter-sucker').'</option>
       <option value="draft" selected="selected">'.__('Draft', 'twitter-sucker').'</option>
     '; 
  } else {
    $status_options ='
       <option value="publish" selected="selected">'.__('Publish', 'twitter-sucker').'</option>
       <option value="draft">'.__('Draft', 'twitter-sucker').'</option>
     '; 
  }
  
  // Drop replies options
  $drop_replies_check = get_option('ws_ts_drop_replies') == 1 ? 'checked="true"' : '';
  // Chrono options
  $chrono_check = get_option('ws_ts_chrono') == 1 ? 'checked="true"' : '';
  $includeRTs_check = get_option('ws_ts_includeRTs') == 1 ? 'checked="true"' : ''; 
  
  // Default the number of tweets to 20
  $num_tweets_value = get_option('ws_ts_num_tweets');
  if (!$num_tweets_value) { $num_tweets_value =  20; }

  print('
  
  <style>
  
  p.submit {
  padding: 5px;
margin-top: 20px;
  }
  div.ws_ts_error {
  margin: 20px 100px 20px 50px;    
  padding: 10px;
  border: 2px solid red;
    background-color: #FFEEEE;
  }
  .option {margin: 10px;}
  .section {
    border-bottom: 1px dashed #888;
    margin-bottom: 10px;
    padding-bottom: 10px;
  }
  fieldset h3 {
    color: #888;
  }
  </style>


  <script text="text/javascript">
    var ws = {
      // Toggle the day of week option
      toggleDoWOption: function(select) {

         if (jQuery(select).val() == 0) {
            jQuery("#dowOptionDiv").hide();
        } else {
            jQuery("#dowOptionDiv").show();
        }
      }
    }
  </script>

  <div class="wrap">
  <h2>'.__('Twitter Sucker Options', 'twitter-tools').'</h2>
  
  '.ws_getErrorMessage().'
    <hr/><form id="ws_twittertools" name="ws_twittertools" action="options.php" method="post">
  '.wp_nonce_field('update-options').'
        
    <input type="hidden" name="action" value="update" />
    <input type="hidden" name="page_options" value="ws_ts_username,ws_ts_category,ws_ts_author,ws_ts_drop_replies,ws_ts_post_tags,ws_ts_min_tweets,ws_ts_chrono,ws_ts_num_tweets,ws_ts_showdate,ws_ts_showtime, ws_ts_excerpt, ws_ts_dateFormat, ws_ts_checktest, ws_ts_status, ws_ts_includeRTs"/>
  
  <fieldset class="options section">
  <h3>Twitter Account Info</h3>
  <div class="option">
  
  <label for="ws_ts_username">'.__('Username', 'twitter-sucker').': </label>
  <input type="text" size="25" name="ws_ts_username" id="ws_ts_username" value="'.get_option('ws_ts_username').'" autocomplete="off" />
  </div>

  </fieldset>

  <fieldset class="options section">
  <h3>Post Options</h3>

  <div class="option">
  <label for="ws_ts_category">'.__('Post Category:', 'twitter-sucker').'</label>
  <select name="ws_ts_category" id="ws_ts_category">'.$cat_options.'</select>
  </div>
  <div class="option">
  <label for="ws_ts_post_tags">'.__('Post Tags:', 'twitter-sucker').'</label>
  <input size="50" name="ws_ts_post_tags" id="ws_ts_post_tags" value="'.get_option('ws_ts_post_tags').'">
  <span>'._('Separate multiple tags with commas. Example: tweets, twitter').'</span>
  </div>

  <div class="option">
  <label for="ws_ts_author">'.__('Post Author:', 'twitter-sucker').'</label>
  <select name="ws_ts_author" id="ws_blog_post_author">'.$author_options.'</select>
  </div>

  <div class="option">
  <label for="ws_ts_status">'.__('Post Status:', 'twitter-sucker').'</label>
  <select name="ws_ts_status" id="ws_blog_post_">'.$status_options.'</select>
  </div>

  <div class="option">
  <label for="ws_ts_drop_replies">'.__('Exclude @reply tweets?', 'twitter-sucker').'</label>
  <input value="1" type="checkbox" name="ws_ts_drop_replies" id="ws_ts_drop_replies" '.$drop_replies_check.'/_check>
  </div>

  <div class="option">
  <label for="ws_ts_includeRTs">'.__('Include retweets?', 'twitter-sucker').'</label>
  <input value="1" type="checkbox" name="ws_ts_includeRTs" id="ws_ts_includeRts" '.$includeRTs_check.'/>
  </div>  

  <div class="option">
  <label for="ws_ts_num_tweets">'.__('Maximum number of tweets to retrieve (Twitter caps at 200): ', 'twitter-sucker').'</label>
  <input name="ws_ts_num_tweets" id="ws_ts_num_tweets" size="3" style="text-align: right" value="'.$num_tweets_value.'">
  </div>
  </fieldset>
  <div class="section">
  <p class="submit">
  <input type="submit" name="submit" value="'.__('Update Options', 'twitter-sucker').'" />
  </p>
  </div>

  </form>

   <div class="section">
     <form method="POST">
       <p class="submit" style="margin: 0;">
        <input type="hidden" name="action" value="ping"/>
       <input type="submit" name="submit" value="'.__('Ping Twitter', 'twitter-sucker').'" />
       <span style="'.$ping_style.'">'.$ping_message.'</span>
  
       </p>
      </form>
      <form method="POST">
      <p class="submit" style="margin: 0;">
       <input type="hidden" name="action" value="resetdb"/>   
       <input type="submit" name="submit" value="'.__('Reset Database', 'twitter-sucker').'" />
        <span>Clicking this button resets the Twitter Sucker database and may result in duplicate posts.</span>
      </p>
     </form>
   </div>
  
</div>
  ');
}

function ws_getErrorMessage() {
  $error = get_option('ws_ts_error');
  if ($error) {
    return '<div class="ws_ts_error">'.$error.'</div>';
  }
}

define('MYPLUGIN_FOLDER', dirname(__FILE__));




//=====================================================================
if (!class_exists('Services_JSON')) {

// PEAR JSON class

/**
* Converts to and from JSON format.
*
* JSON (JavaScript Object Notation) is a lightweight data-interchange
* format. It is easy for humans to read and write. It is easy for machines
* to parse and generate. It is based on a subset of the JavaScript
* Programming Language, Standard ECMA-262 3rd Edition - December 1999.
* This feature can also be found in  Python. JSON is a text format that is
* completely language independent but uses conventions that are familiar
* to programmers of the C-family of languages, including C, C++, C#, Java,
* JavaScript, Perl, TCL, and many others. These properties make JSON an
* ideal data-interchange language.
*
* This package provides a simple encoder and decoder for JSON notation. It
* is intended for use with client-side Javascript applications that make
* use of HTTPRequest to perform server communication functions - data can
* be encoded into JSON notation for use in a client-side javascript, or
* decoded from incoming Javascript requests. JSON format is native to
* Javascript, and can be directly eval()'ed with no further parsing
* overhead
*
* All strings should be in ASCII or UTF-8 format!
*
* LICENSE: Redistribution and use in source and binary forms, with or
* without modification, are permitted provided that the following
* conditions are met: Redistributions of source code must retain the
* above copyright notice, this list of conditions and the following
* disclaimer. Redistributions in binary form must reproduce the above
* copyright notice, this list of conditions and the following disclaimer
* in the documentation and/or other materials provided with the
* distribution.
*
* THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED
* WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF
* MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN
* NO EVENT SHALL CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
* INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
* BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS
* OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
* ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR
* TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE
* USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH
* DAMAGE.
*
* @category
* @package     Services_JSON
* @author      Michal Migurski <mike-json@teczno.com>
* @author      Matt Knapp <mdknapp[at]gmail[dot]com>
* @author      Brett Stimmerman <brettstimmerman[at]gmail[dot]com>
* @copyright   2005 Michal Migurski
* @version     CVS: $Id: JSON.php,v 1.31 2006/06/28 05:54:17 migurski Exp $
* @license     http://www.opensource.org/licenses/bsd-license.php
* @link        http://pear.php.net/pepr/pepr-proposal-show.php?id=198
*/

/**
* Marker constant for Services_JSON::decode(), used to flag stack state
*/
define('SERVICES_JSON_SLICE',   1);

/**
* Marker constant for Services_JSON::decode(), used to flag stack state
*/
define('SERVICES_JSON_IN_STR',  2);

/**
* Marker constant for Services_JSON::decode(), used to flag stack state
*/
define('SERVICES_JSON_IN_ARR',  3);

/**
* Marker constant for Services_JSON::decode(), used to flag stack state
*/
define('SERVICES_JSON_IN_OBJ',  4);

/**
* Marker constant for Services_JSON::decode(), used to flag stack state
*/
define('SERVICES_JSON_IN_CMT', 5);

/**
* Behavior switch for Services_JSON::decode()
*/
define('SERVICES_JSON_LOOSE_TYPE', 16);

/**
* Behavior switch for Services_JSON::decode()
*/
define('SERVICES_JSON_SUPPRESS_ERRORS', 32);

/**
* Converts to and from JSON format.
*
* Brief example of use:
*
* <code>
* // create a new instance of Services_JSON
* $json = new Services_JSON();
*
* // convert a complexe value to JSON notation, and send it to the browser
* $value = array('foo', 'bar', array(1, 2, 'baz'), array(3, array(4)));
* $output = $json->encode($value);
*
* print($output);
* // prints: ["foo","bar",[1,2,"baz"],[3,[4]]]
*
* // accept incoming POST data, assumed to be in JSON notation
* $input = file_get_contents('php://input', 1000000);
* $value = $json->decode($input);
* </code>
*/
class Services_JSON
{
   /**
    * constructs a new JSON instance
    *
    * @param    int     $use    object behavior flags; combine with boolean-OR
    *
    *                           possible values:
    *                           - SERVICES_JSON_LOOSE_TYPE:  loose typing.
    *                                   "{...}" syntax creates associative arrays
    *                                   instead of objects in decode().
    *                           - SERVICES_JSON_SUPPRESS_ERRORS:  error suppression.
    *                                   Values which can't be encoded (e.g. resources)
    *                                   appear as NULL instead of throwing errors.
    *                                   By default, a deeply-nested resource will
    *                                   bubble up with an error, so all return values
    *                                   from encode() should be checked with isError()
    */
    function Services_JSON($use = 0)
    {
        $this->use = $use;
    }

   /**
    * convert a string from one UTF-16 char to one UTF-8 char
    *
    * Normally should be handled by mb_convert_encoding, but
    * provides a slower PHP-only method for installations
    * that lack the multibye string extension.
    *
    * @param    string  $utf16  UTF-16 character
    * @return   string  UTF-8 character
    * @access   private
    */
    function utf162utf8($utf16)
    {
        // oh please oh please oh please oh please oh please
        if(function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($utf16, 'UTF-8', 'UTF-16');
        }

        $bytes = (ord($utf16{0}) << 8) | ord($utf16{1});

        switch(true) {
            case ((0x7F & $bytes) == $bytes):
                // this case should never be reached, because we are in ASCII range
                // see: http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                return chr(0x7F & $bytes);

            case (0x07FF & $bytes) == $bytes:
                // return a 2-byte UTF-8 character
                // see: http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                return chr(0xC0 | (($bytes >> 6) & 0x1F))
                     . chr(0x80 | ($bytes & 0x3F));

            case (0xFFFF & $bytes) == $bytes:
                // return a 3-byte UTF-8 character
                // see: http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                return chr(0xE0 | (($bytes >> 12) & 0x0F))
                     . chr(0x80 | (($bytes >> 6) & 0x3F))
                     . chr(0x80 | ($bytes & 0x3F));
        }

        // ignoring UTF-32 for now, sorry
        return '';
    }

   /**
    * convert a string from one UTF-8 char to one UTF-16 char
    *
    * Normally should be handled by mb_convert_encoding, but
    * provides a slower PHP-only method for installations
    * that lack the multibye string extension.
    *
    * @param    string  $utf8   UTF-8 character
    * @return   string  UTF-16 character
    * @access   private
    */
    function utf82utf16($utf8)
    {
        // oh please oh please oh please oh please oh please
        if(function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($utf8, 'UTF-16', 'UTF-8');
        }

        switch(strlen($utf8)) {
            case 1:
                // this case should never be reached, because we are in ASCII range
                // see: http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                return $utf8;

            case 2:
                // return a UTF-16 character from a 2-byte UTF-8 char
                // see: http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                return chr(0x07 & (ord($utf8{0}) >> 2))
                     . chr((0xC0 & (ord($utf8{0}) << 6))
                         | (0x3F & ord($utf8{1})));

            case 3:
                // return a UTF-16 character from a 3-byte UTF-8 char
                // see: http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                return chr((0xF0 & (ord($utf8{0}) << 4))
                         | (0x0F & (ord($utf8{1}) >> 2)))
                     . chr((0xC0 & (ord($utf8{1}) << 6))
                         | (0x7F & ord($utf8{2})));
        }

        // ignoring UTF-32 for now, sorry
        return '';
    }

   /**
    * encodes an arbitrary variable into JSON format
    *
    * @param    mixed   $var    any number, boolean, string, array, or object to be encoded.
    *                           see argument 1 to Services_JSON() above for array-parsing behavior.
    *                           if var is a strng, note that encode() always expects it
    *                           to be in ASCII or UTF-8 format!
    *
    * @return   mixed   JSON string representation of input var or an error if a problem occurs
    * @access   public
    */
    function encode($var)
    {
        switch (gettype($var)) {
            case 'boolean':
                return $var ? 'true' : 'false';

            case 'NULL':
                return 'null';

            case 'integer':
                return (int) $var;

            case 'double':
            case 'float':
                return (float) $var;

            case 'string':
                // STRINGS ARE EXPECTED TO BE IN ASCII OR UTF-8 FORMAT
                $ascii = '';
                $strlen_var = strlen($var);

               /*
                * Iterate over every character in the string,
                * escaping with a slash or encoding to UTF-8 where necessary
                */
                for ($c = 0; $c < $strlen_var; ++$c) {

                    $ord_var_c = ord($var{$c});

                    switch (true) {
                        case $ord_var_c == 0x08:
                            $ascii .= '\b';
                            break;
                        case $ord_var_c == 0x09:
                            $ascii .= '\t';
                            break;
                        case $ord_var_c == 0x0A:
                            $ascii .= '\n';
                            break;
                        case $ord_var_c == 0x0C:
                            $ascii .= '\f';
                            break;
                        case $ord_var_c == 0x0D:
                            $ascii .= '\r';
                            break;

                        case $ord_var_c == 0x22:
                        case $ord_var_c == 0x2F:
                        case $ord_var_c == 0x5C:
                            // double quote, slash, slosh
                            $ascii .= '\\'.$var{$c};
                            break;

                        case (($ord_var_c >= 0x20) && ($ord_var_c <= 0x7F)):
                            // characters U-00000000 - U-0000007F (same as ASCII)
                            $ascii .= $var{$c};
                            break;

                        case (($ord_var_c & 0xE0) == 0xC0):
                            // characters U-00000080 - U-000007FF, mask 110XXXXX
                            // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                            $char = pack('C*', $ord_var_c, ord($var{$c + 1}));
                            $c += 1;
                            $utf16 = $this->utf82utf16($char);
                            $ascii .= sprintf('\u%04s', bin2hex($utf16));
                            break;

                        case (($ord_var_c & 0xF0) == 0xE0):
                            // characters U-00000800 - U-0000FFFF, mask 1110XXXX
                            // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                            $char = pack('C*', $ord_var_c,
                                         ord($var{$c + 1}),
                                         ord($var{$c + 2}));
                            $c += 2;
                            $utf16 = $this->utf82utf16($char);
                            $ascii .= sprintf('\u%04s', bin2hex($utf16));
                            break;

                        case (($ord_var_c & 0xF8) == 0xF0):
                            // characters U-00010000 - U-001FFFFF, mask 11110XXX
                            // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                            $char = pack('C*', $ord_var_c,
                                         ord($var{$c + 1}),
                                         ord($var{$c + 2}),
                                         ord($var{$c + 3}));
                            $c += 3;
                            $utf16 = $this->utf82utf16($char);
                            $ascii .= sprintf('\u%04s', bin2hex($utf16));
                            break;

                        case (($ord_var_c & 0xFC) == 0xF8):
                            // characters U-00200000 - U-03FFFFFF, mask 111110XX
                            // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                            $char = pack('C*', $ord_var_c,
                                         ord($var{$c + 1}),
                                         ord($var{$c + 2}),
                                         ord($var{$c + 3}),
                                         ord($var{$c + 4}));
                            $c += 4;
                            $utf16 = $this->utf82utf16($char);
                            $ascii .= sprintf('\u%04s', bin2hex($utf16));
                            break;

                        case (($ord_var_c & 0xFE) == 0xFC):
                            // characters U-04000000 - U-7FFFFFFF, mask 1111110X
                            // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                            $char = pack('C*', $ord_var_c,
                                         ord($var{$c + 1}),
                                         ord($var{$c + 2}),
                                         ord($var{$c + 3}),
                                         ord($var{$c + 4}),
                                         ord($var{$c + 5}));
                            $c += 5;
                            $utf16 = $this->utf82utf16($char);
                            $ascii .= sprintf('\u%04s', bin2hex($utf16));
                            break;
                    }
                }

                return '"'.$ascii.'"';

            case 'array':
               /*
                * As per JSON spec if any array key is not an integer
                * we must treat the the whole array as an object. We
                * also try to catch a sparsely populated associative
                * array with numeric keys here because some JS engines
                * will create an array with empty indexes up to
                * max_index which can cause memory issues and because
                * the keys, which may be relevant, will be remapped
                * otherwise.
                *
                * As per the ECMA and JSON specification an object may
                * have any string as a property. Unfortunately due to
                * a hole in the ECMA specification if the key is a
                * ECMA reserved word or starts with a digit the
                * parameter is only accessible using ECMAScript's
                * bracket notation.
                */

                // treat as a JSON object
                if (is_array($var) && count($var) && (array_keys($var) !== range(0, sizeof($var) - 1))) {
                    $properties = array_map(array($this, 'name_value'),
                                            array_keys($var),
                                            array_values($var));

                    foreach($properties as $property) {
                        if(Services_JSON::isError($property)) {
                            return $property;
                        }
                    }

                    return '{' . join(',', $properties) . '}';
                }

                // treat it like a regular array
                $elements = array_map(array($this, 'encode'), $var);

                foreach($elements as $element) {
                    if(Services_JSON::isError($element)) {
                        return $element;
                    }
                }

                return '[' . join(',', $elements) . ']';

            case 'object':
                $vars = get_object_vars($var);

                $properties = array_map(array($this, 'name_value'),
                                        array_keys($vars),
                                        array_values($vars));

                foreach($properties as $property) {
                    if(Services_JSON::isError($property)) {
                        return $property;
                    }
                }

                return '{' . join(',', $properties) . '}';

            default:
                return ($this->use & SERVICES_JSON_SUPPRESS_ERRORS)
                    ? 'null'
                    : new Services_JSON_Error(gettype($var)." can not be encoded as JSON string");
        }
    }

   /**
    * array-walking function for use in generating JSON-formatted name-value pairs
    *
    * @param    string  $name   name of key to use
    * @param    mixed   $value  reference to an array element to be encoded
    *
    * @return   string  JSON-formatted name-value pair, like '"name":value'
    * @access   private
    */
    function name_value($name, $value)
    {
        $encoded_value = $this->encode($value);

        if(Services_JSON::isError($encoded_value)) {
            return $encoded_value;
        }

        return $this->encode(strval($name)) . ':' . $encoded_value;
    }

   /**
    * reduce a string by removing leading and trailing comments and whitespace
    *
    * @param    $str    string      string value to strip of comments and whitespace
    *
    * @return   string  string value stripped of comments and whitespace
    * @access   private
    */
    function reduce_string($str)
    {
        $str = preg_replace(array(

                // eliminate single line comments in '// ...' form
                '#^\s*//(.+)$#m',

                // eliminate multi-line comments in '/* ... */' form, at start of string
                '#^\s*/\*(.+)\*/#Us',

                // eliminate multi-line comments in '/* ... */' form, at end of string
                '#/\*(.+)\*/\s*$#Us'

            ), '', $str);

        // eliminate extraneous space
        return trim($str);
    }

   /**
    * decodes a JSON string into appropriate variable
    *
    * @param    string  $str    JSON-formatted string
    *
    * @return   mixed   number, boolean, string, array, or object
    *                   corresponding to given JSON input string.
    *                   See argument 1 to Services_JSON() above for object-output behavior.
    *                   Note that decode() always returns strings
    *                   in ASCII or UTF-8 format!
    * @access   public
    */
    function decode($str)
    {
        $str = $this->reduce_string($str);

        switch (strtolower($str)) {
            case 'true':
                return true;

            case 'false':
                return false;

            case 'null':
                return null;

            default:
                $m = array();

                if (is_numeric($str)) {
                    // Lookie-loo, it's a number

                    // This would work on its own, but I'm trying to be
                    // good about returning integers where appropriate:
                    // return (float)$str;

                    // Return float or int, as appropriate
                    return ((float)$str == (integer)$str)
                        ? (integer)$str
                        : (float)$str;

                } elseif (preg_match('/^("|\').*(\1)$/s', $str, $m) && $m[1] == $m[2]) {
                    // STRINGS RETURNED IN UTF-8 FORMAT
                    $delim = substr($str, 0, 1);
                    $chrs = substr($str, 1, -1);
                    $utf8 = '';
                    $strlen_chrs = strlen($chrs);

                    for ($c = 0; $c < $strlen_chrs; ++$c) {

                        $substr_chrs_c_2 = substr($chrs, $c, 2);
                        $ord_chrs_c = ord($chrs{$c});

                        switch (true) {
                            case $substr_chrs_c_2 == '\b':
                                $utf8 .= chr(0x08);
                                ++$c;
                                break;
                            case $substr_chrs_c_2 == '\t':
                                $utf8 .= chr(0x09);
                                ++$c;
                                break;
                            case $substr_chrs_c_2 == '\n':
                                $utf8 .= chr(0x0A);
                                ++$c;
                                break;
                            case $substr_chrs_c_2 == '\f':
                                $utf8 .= chr(0x0C);
                                ++$c;
                                break;
                            case $substr_chrs_c_2 == '\r':
                                $utf8 .= chr(0x0D);
                                ++$c;
                                break;

                            case $substr_chrs_c_2 == '\\"':
                            case $substr_chrs_c_2 == '\\\'':
                            case $substr_chrs_c_2 == '\\\\':
                            case $substr_chrs_c_2 == '\\/':
                                if (($delim == '"' && $substr_chrs_c_2 != '\\\'') ||
                                   ($delim == "'" && $substr_chrs_c_2 != '\\"')) {
                                    $utf8 .= $chrs{++$c};
                                }
                                break;

                            case preg_match('/\\\u[0-9A-F]{4}/i', substr($chrs, $c, 6)):
                                // single, escaped unicode character
                                $utf16 = chr(hexdec(substr($chrs, ($c + 2), 2)))
                                       . chr(hexdec(substr($chrs, ($c + 4), 2)));
                                $utf8 .= $this->utf162utf8($utf16);
                                $c += 5;
                                break;

                            case ($ord_chrs_c >= 0x20) && ($ord_chrs_c <= 0x7F):
                                $utf8 .= $chrs{$c};
                                break;

                            case ($ord_chrs_c & 0xE0) == 0xC0:
                                // characters U-00000080 - U-000007FF, mask 110XXXXX
                                //see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                $utf8 .= substr($chrs, $c, 2);
                                ++$c;
                                break;

                            case ($ord_chrs_c & 0xF0) == 0xE0:
                                // characters U-00000800 - U-0000FFFF, mask 1110XXXX
                                // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                $utf8 .= substr($chrs, $c, 3);
                                $c += 2;
                                break;

                            case ($ord_chrs_c & 0xF8) == 0xF0:
                                // characters U-00010000 - U-001FFFFF, mask 11110XXX
                                // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                $utf8 .= substr($chrs, $c, 4);
                                $c += 3;
                                break;

                            case ($ord_chrs_c & 0xFC) == 0xF8:
                                // characters U-00200000 - U-03FFFFFF, mask 111110XX
                                // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                $utf8 .= substr($chrs, $c, 5);
                                $c += 4;
                                break;

                            case ($ord_chrs_c & 0xFE) == 0xFC:
                                // characters U-04000000 - U-7FFFFFFF, mask 1111110X
                                // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                $utf8 .= substr($chrs, $c, 6);
                                $c += 5;
                                break;

                        }

                    }

                    return $utf8;

                } elseif (preg_match('/^\[.*\]$/s', $str) || preg_match('/^\{.*\}$/s', $str)) {
                    // array, or object notation

                    if ($str{0} == '[') {
                        $stk = array(SERVICES_JSON_IN_ARR);
                        $arr = array();
                    } else {
                        if ($this->use & SERVICES_JSON_LOOSE_TYPE) {
                            $stk = array(SERVICES_JSON_IN_OBJ);
                            $obj = array();
                        } else {
                            $stk = array(SERVICES_JSON_IN_OBJ);
                            $obj = new stdClass();
                        }
                    }

                    array_push($stk, array('what'  => SERVICES_JSON_SLICE,
                                           'where' => 0,
                                           'delim' => false));

                    $chrs = substr($str, 1, -1);
                    $chrs = $this->reduce_string($chrs);

                    if ($chrs == '') {
                        if (reset($stk) == SERVICES_JSON_IN_ARR) {
                            return $arr;

                        } else {
                            return $obj;

                        }
                    }

                    //print("\nparsing {$chrs}\n");

                    $strlen_chrs = strlen($chrs);

                    for ($c = 0; $c <= $strlen_chrs; ++$c) {

                        $top = end($stk);
                        $substr_chrs_c_2 = substr($chrs, $c, 2);

                        if (($c == $strlen_chrs) || (($chrs{$c} == ',') && ($top['what'] == SERVICES_JSON_SLICE))) {
                            // found a comma that is not inside a string, array, etc.,
                            // OR we've reached the end of the character list
                            $slice = substr($chrs, $top['where'], ($c - $top['where']));
                            array_push($stk, array('what' => SERVICES_JSON_SLICE, 'where' => ($c + 1), 'delim' => false));
                            //print("Found split at {$c}: ".substr($chrs, $top['where'], (1 + $c - $top['where']))."\n");

                            if (reset($stk) == SERVICES_JSON_IN_ARR) {
                                // we are in an array, so just push an element onto the stack
                                array_push($arr, $this->decode($slice));

                            } elseif (reset($stk) == SERVICES_JSON_IN_OBJ) {
                                // we are in an object, so figure
                                // out the property name and set an
                                // element in an associative array,
                                // for now
                                $parts = array();
                                
                                if (preg_match('/^\s*(["\'].*[^\\\]["\'])\s*:\s*(\S.*),?$/Uis', $slice, $parts)) {
                                    // "name":value pair
                                    $key = $this->decode($parts[1]);
                                    $val = $this->decode($parts[2]);

                                    if ($this->use & SERVICES_JSON_LOOSE_TYPE) {
                                        $obj[$key] = $val;
                                    } else {
                                        $obj->$key = $val;
                                    }
                                } elseif (preg_match('/^\s*(\w+)\s*:\s*(\S.*),?$/Uis', $slice, $parts)) {
                                    // name:value pair, where name is unquoted
                                    $key = $parts[1];
                                    $val = $this->decode($parts[2]);

                                    if ($this->use & SERVICES_JSON_LOOSE_TYPE) {
                                        $obj[$key] = $val;
                                    } else {
                                        $obj->$key = $val;
                                    }
                                }

                            }

                        } elseif ((($chrs{$c} == '"') || ($chrs{$c} == "'")) && ($top['what'] != SERVICES_JSON_IN_STR)) {
                            // found a quote, and we are not inside a string
                            array_push($stk, array('what' => SERVICES_JSON_IN_STR, 'where' => $c, 'delim' => $chrs{$c}));
                            //print("Found start of string at {$c}\n");

                        } elseif (($chrs{$c} == $top['delim']) &&
                                 ($top['what'] == SERVICES_JSON_IN_STR) &&
                                 ((strlen(substr($chrs, 0, $c)) - strlen(rtrim(substr($chrs, 0, $c), '\\'))) % 2 != 1)) {
                            // found a quote, we're in a string, and it's not escaped
                            // we know that it's not escaped becase there is _not_ an
                            // odd number of backslashes at the end of the string so far
                            array_pop($stk);
                            //print("Found end of string at {$c}: ".substr($chrs, $top['where'], (1 + 1 + $c - $top['where']))."\n");

                        } elseif (($chrs{$c} == '[') &&
                                 in_array($top['what'], array(SERVICES_JSON_SLICE, SERVICES_JSON_IN_ARR, SERVICES_JSON_IN_OBJ))) {
                            // found a left-bracket, and we are in an array, object, or slice
                            array_push($stk, array('what' => SERVICES_JSON_IN_ARR, 'where' => $c, 'delim' => false));
                            //print("Found start of array at {$c}\n");

                        } elseif (($chrs{$c} == ']') && ($top['what'] == SERVICES_JSON_IN_ARR)) {
                            // found a right-bracket, and we're in an array
                            array_pop($stk);
                            //print("Found end of array at {$c}: ".substr($chrs, $top['where'], (1 + $c - $top['where']))."\n");

                        } elseif (($chrs{$c} == '{') &&
                                 in_array($top['what'], array(SERVICES_JSON_SLICE, SERVICES_JSON_IN_ARR, SERVICES_JSON_IN_OBJ))) {
                            // found a left-brace, and we are in an array, object, or slice
                            array_push($stk, array('what' => SERVICES_JSON_IN_OBJ, 'where' => $c, 'delim' => false));
                            //print("Found start of object at {$c}\n");

                        } elseif (($chrs{$c} == '}') && ($top['what'] == SERVICES_JSON_IN_OBJ)) {
                            // found a right-brace, and we're in an object
                            array_pop($stk);
                            //print("Found end of object at {$c}: ".substr($chrs, $top['where'], (1 + $c - $top['where']))."\n");

                        } elseif (($substr_chrs_c_2 == '/*') &&
                                 in_array($top['what'], array(SERVICES_JSON_SLICE, SERVICES_JSON_IN_ARR, SERVICES_JSON_IN_OBJ))) {
                            // found a comment start, and we are in an array, object, or slice
                            array_push($stk, array('what' => SERVICES_JSON_IN_CMT, 'where' => $c, 'delim' => false));
                            $c++;
                            //print("Found start of comment at {$c}\n");

                        } elseif (($substr_chrs_c_2 == '*/') && ($top['what'] == SERVICES_JSON_IN_CMT)) {
                            // found a comment end, and we're in one now
                            array_pop($stk);
                            $c++;

                            for ($i = $top['where']; $i <= $c; ++$i)
                                $chrs = substr_replace($chrs, ' ', $i, 1);

                            //print("Found end of comment at {$c}: ".substr($chrs, $top['where'], (1 + $c - $top['where']))."\n");

                        }

                    }

                    if (reset($stk) == SERVICES_JSON_IN_ARR) {
                        return $arr;

                    } elseif (reset($stk) == SERVICES_JSON_IN_OBJ) {
                        return $obj;

                    }

                }
        }
    }

    /**
     * @todo Ultimately, this should just call PEAR::isError()
     */
    function isError($data, $code = null)
    {
        if (class_exists('pear')) {
            return PEAR::isError($data, $code);
        } elseif (is_object($data) && (get_class($data) == 'services_json_error' ||
                                 is_subclass_of($data, 'services_json_error'))) {
            return true;
        }

        return false;
    }
}

if (class_exists('PEAR_Error')) {

    class Services_JSON_Error extends PEAR_Error
    {
        function Services_JSON_Error($message = 'unknown error', $code = null,
                                     $mode = null, $options = null, $userinfo = null)
        {
            parent::PEAR_Error($message, $code, $mode, $options, $userinfo);
        }
    }

} else {

    /**
     * @todo Ultimately, this class shall be descended from PEAR_Error
     */
    class Services_JSON_Error
    {
        function Services_JSON_Error($message = 'unknown error', $code = null,
                                     $mode = null, $options = null, $userinfo = null)
        {

        }
    }

}


}


?>
