<?php
/*
 ******************************************************************************************************************
 *  Author:           Nam Tran, Grey Hat Apps
 *  Email Address:    nam@greyhatapps.com
 *  Date Created:     10/21/2011
 *
 ******************************************************************************************************************
 *  Class: MetaCritic
 *
 *    Class used for MetaCritic interactions
 *
 ******************************************************************************************************************
 */
  include_once("inc-settings.php");
  include_once("inc-memcache.php");
  include_once("core/base.php");
  include_once("core/browser.php");
  include_once("core/format.php");
  include_once("core/log.php");
  include_once("core/object.php");

  class MetaCritic extends Base
  {
    public static $baseUrl = "http://www.metacritic.com/game/";
    public static $name = "METACRITIC";

    public $url;
    public $keyvalue;
    public $source;
    public $dateExtracted;
    public $score;
    public $reviews;

  // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
  // +  getReviews
  // +
  // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    static public function getReviews($pKeyValue, $pName, $pPlatform, $pIgnoreCache=false)
    {
      $obj = new MetaCritic();

      global $memcache;
      $cacheStr = md5(self::$name . "-" . $pKeyValue);
      $mc_result = $memcache->get($cacheStr);

      // If memcache miss, extract pricing info from web query
      if( (!$mc_result) || ($pIgnoreCache) )
      {
        $url = self::generateUrl($pName, $pPlatform);
        $html = Browser::get($url);

        if(self::isValidItem($html))
        {
          $obj->url = $url;
          $obj->keyvalue = $pKeyValue;
          $obj->source = _SOURCE_CRAWL;
          $obj->dateExtracted = time();

          // Extract individual metacritic reviews
          $obj->metascore = self::getOverallScore($url, $html);
          $obj->reviews = self::getAllReviews($url, $html);

          // set cache
          $objStr = json_encode($obj);
          $memcache->set($cacheStr, $objStr, false, _MEMCACHE_METACRITICREVIEWS);
        }
      }
      else
      {
        $obj = json_decode($mc_result);
        $obj->source = _SOURCE_CACHE;
      }

      return $obj;
    }

  // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
  // +  generateUrl
  // +
  // +
  // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    static public function generateUrl($pName, $pPlatform)
    {
      switch(Format::forCompare($pPlatform))
      {
        case _PLATFORM_3DS:
          $platform = "3ds";
          break;
        case _PLATFORM_DS:
          $platform = "ds";
          break;
        case _PLATFORM_PC:
          $platform = "pc";
          break;
        case _PLATFORM_PS2:
          $platform = "playstation-2";
          break;
        case _PLATFORM_PS3:
          $platform = "playstation-3";
          break;
        case _PLATFORM_PSP:
          $platform = "psp";
          break;
        case _PLATFORM_VITA:
          $platform = "playstation-vita";
          break;
        case _PLATFORM_XBOX360:
          $platform = "xbox-360";
          break;
        case _PLATFORM_WII:
          $platform = "wii";
          break;
        default:
          $platform = "other";
          break;
      }

      // Replace "." with ""
      $name = $pName;
      $name = str_replace(".", "", $name);
      $name = str_replace("Collector's Edition", "", $name);
      $name = str_replace("Collectors Edition", "", $name);
      $name = str_replace("Kollector's Edition", "", $name);
      $name = str_replace("Kollectors Edition", "", $name);

      // Remove special edition tags
      // ie. Battlefield 3 - Limited Edition => Battlefield 3
      $array_names = explode("-", $name);
      $name = trim($array_names[0]);
      $name = Format::URLSafeString($name);

      $url = self::$baseUrl . $platform . "/" . $name . "/critic-reviews";

      return $url;
    }

  // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
  // +  isValidItem
  // +
  // +  Determine if the HTML is for a valid item
  // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    static public function isValidItem($pHTML)
    {
      return true;
    }

  // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
  // +  getOverallScore
  // +
  // +
  // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    static public function getOverallScore($pUrl, $pHTML="")
    {
      if($pHTML == "")
        $html = Browser::get($pUrl);
      else
        $html = $pHTML;

      if($html === false) { return ""; }

      // Extract score
      /*
            <div class="label">Metascore</div>
               <div class="data metascore score_outstanding" >
                 <span class="score_value">95</span>
                 <span class="score_total">out of 100</span>
      */
      $kw_start = "Metascore</div>";
      $kw_end = "out of 100</span>";
      $html_value = Browser::stripHTML($html, $kw_start, $kw_end);
      if(strlen($html) == strlen($html_value))
      {
        // Keyword not found
        return "";
      }

      $kw_start = "score_value\">";
      $kw_end = "</span>";
      $html_value = Browser::stripHTML($html_value, $kw_start, $kw_end);

      // Format HTML value
      $html_value = strip_tags($html_value);
      $value = Browser::formatHTMLText($html_value);

      // Handle special case when there are not enough critic reviews and user revews are pulled in instead.
      // User reviews are 0-10, so need to multiply by 10
      if( (stristr($value, ".")) || ($value <= 10) )
      {
        $value = $value * 10;
      }

      return $value;
    }

  // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
  // +  getAllReviews
  // +
  // +
  // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    static public function getAllReviews($pUrl, $pHTML="")
    {
      if($pHTML == "")
        $html = Browser::get($pUrl);
      else
        $html = $pHTML;

      if($html === false) { return ""; }

      // Extract reviews
      /*
            .
            .
                <div class="review_critic"><div class="source"><a href="/publication/machinima?filter=games">Machinima</a></div><div class="date">Oct 16, 2011</div></div>
                <div class="review_grade critscore critscore_outstanding">
                    95
                </div>
              </div>
              <div class="review_body">
                Review text
              </div>
            </div>
            <div class="review_section review_actions">
              <ul class="review_actions">
                <li class="review_action author_reviews">...</li>
                <li class="review_action full_review"><a ... href="">Read full review</a></li>
              </ul>
            </div>

      */

      $array_reviews = array();

      // count the number of critic sources (sources with a website link)
      $totalCnt = substr_count($html, "<div class=\"review_critic\"><div class=\"source\">");
      $nextOffset = 0;

      // Extract critic reviews
      for($i=0; $i<$totalCnt; $i++)
      {
        $obj = new Object();

        $kw_start = "<div class=\"review_critic\"><div class=\"source\">";
        $kw_end = "</div></div></div></div>";
        $html_snippet = Browser::stripHTML($html, $kw_start, $kw_end, $nextOffset, $nextOffset);

        // Extract critic's name
        $kw_start = "\">";
        $kw_end = "</a>";
        $html_value = Browser::stripHTML($html_snippet, $kw_start, $kw_end);
        $obj->name = Browser::formatHTMLText($html_value);

        // Extract critic's score
        $kw_start = "<div class=\"review_grade";
        $kw_end = "</div>";
        $html_value = Browser::stripHTML($html_snippet, $kw_start, $kw_end);
        $kw_start = ">";
        $kw_end = "";
        $html_value = Browser::stripHTML($html_value, $kw_start, $kw_end);
        $obj->rating = Browser::formatHTMLText($html_value);

        // Extract critic's review date
        $kw_start = "<div class=\"date\">";
        $kw_end = "</div>";
        $html_value = Browser::stripHTML($html_snippet, $kw_start, $kw_end);
        $obj->dateReviewed = Browser::formatHTMLText($html_value);

        // Extract critic's review snippet
        $kw_start = "review_body\">";
        $kw_end = "</div>";
        $html_value = Browser::stripHTML($html_snippet, $kw_start, $kw_end);
        $obj->snippet = Browser::formatHTMLText($html_value, 2000);

        // Extract critic's link to full review
        $kw_start = "review_action full_review\"><a ";
        $kw_end = ">";
        $html_value = Browser::stripHTML($html_snippet, $kw_start, $kw_end);
        $kw_start = "href=\"";
        $kw_end = "\"";
        $html_value = Browser::stripHTML($html_value, $kw_start, $kw_end);
        $obj->url = Browser::formatHTMLText($html_value);

        // if URL = "/...", prepend metacritic URL
        if(substr($obj->url, 0, 1) == "/")
        {
          $obj->url = "http://www.metacritic.com" . $obj->url;
        }

        array_push($array_reviews, $obj);
      }

      return $array_reviews;
    }

  // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
  // +  deleteCache
  // +
  // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    static public function deleteCacheEntry($pKeyValue)
    {
      global $memcache;
      $cacheStr = md5(self::$name . "-" . $pKeyValue);
      $memcache->delete($cacheStr);
    }

  }
?>