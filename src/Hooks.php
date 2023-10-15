<?php

namespace MediaWiki\Extension\Matomo;

use RequestContext;
use Xml;

class Hooks
{

    /** @var string|null Searched term in Special:Search. */
    public static $searchTerm = null;

    /** @var string|null Search profile in Special:Search (search category in Piwik vocabulary). */
    public static $searchProfile = null;

    /** @var int|null Number of results in Special:Search. */
    public static $searchCount = null;

    /**
     * Initialize the Matomo hook
     *
     * @param string $skin
     * @param string &$text
     * @return bool
     */
    public static function MatomoSetup($skin, &$text)
    {
        $text = self::addMatomo($skin->getTitle());
    }

    /**
     * Get parameter with either the new prefix $wgMatomo or the old $wgPiwik.
     *
     * @param string $name Parameter name without any prefix.
     * @return mixed|null Parameter value.
     */
    public static function getParameter($name)
    {
        $config = \MediaWiki\MediaWikiServices::getInstance()->getMainConfig();
        if ($config->has("Piwik$name")) {
            return $config->get("Piwik$name");
        } elseif ($config->has("Matomo$name")) {
            return $config->get("Matomo$name");
        }
        return null;
    }

    /**
     * Hook to save some data in Special:Search.
     *
     * @param string $term Searched term.
     * @param SearchResultSet|null $titleMatches Results in the titles.
     * @param SearchResultSet|null $textMatches Results in the fulltext.
     * @return true
     */
    public static function onSpecialSearchResults($term, $titleMatches, $textMatches)
    {
        self::$searchTerm = $term;
        self::$searchCount = 0;
        if ($titleMatches instanceof SearchResultSet) {
            self::$searchCount += (int)$titleMatches->numRows();
        }
        if ($textMatches instanceof SearchResultSet) {
            self::$searchCount += (int)$textMatches->numRows();
        }
        return true;
    }

    /**
     * Hook to save some data in Special:Search.
     *
     * @param SpecialSearch $search Special page.
     * @param string|null $profile Search profile.
     * @param SearchEngine $engine Search engine.
     * @return true
     */
    public static function onSpecialSearchSetupEngine($search, $profile, $engine)
    {
        self::$searchProfile = $profile;
        return true;
    }

    /**
     * Add Matomo script
     * @param string $title
     * @return string|null
     */
    public static function addMatomo($title)
    {
        $user = RequestContext::getMain()->getUser();
        // Is Matomo disabled for bots?
        if ($user->isAllowed('bot') && self::getParameter('IgnoreBots')) {
            return;
        }

        $idSite = self::getParameter('IDSite');
        $matomoURL = self::getParameter('URL');

        // Missing configuration parameters
        if (empty($idSite) || empty($matomoURL)) {
            return;
        }

        $finalActionName = self::getParameter('ActionName');
        if (self::getParameter('UsePageTitle')) {
            $finalActionName .= $title->getPrefixedText();
        }

        // Track search results
        $urlTrackingSearch = '';
        if (self::$searchTerm !== null) {
            // JavaScript
            $jsTerm = Xml::encodeJsVar(self::$searchTerm);
            $jsCategory = self::$searchProfile === null ? 'false' : Xml::encodeJsVar(self::$searchProfile);
            $jsResultsCount = self::$searchCount === null ? 'false' : self::$searchCount;
            $jsTrackingSearch = ",$jsTerm,$jsCategory,$jsResultsCount";

            // URL
            $urlTrackingSearch = ['search' => self::$searchTerm];
            if (self::$searchProfile !== null) {
                $urlTrackingSearch += ['search_cat' => self::$searchProfile];
            }
            if (self::$searchCount !== null) {
                $urlTrackingSearch += ['search_count' => self::$searchCount];
            }
            $urlTrackingSearch = '&' . wfArrayToCgi($urlTrackingSearch);
        }

        // Track username based on https://matomo.org/docs/user-id/ The user
        // name for anonymous visitors is their IP address which Matomo already
        // records.
        if (self::getParameter('TrackUsernames') && $user->isRegistered()) {
            $username = Xml::encodeJsVar($user->getName());
        }

        // Prevent XSS
        $finalActionName = Xml::encodeJsVar($finalActionName);
        $finalRequestUri = Xml::encodeJsVar($_SERVER["REQUEST_URI"]);

        $headerArray = [
            'User-Agent: ' . $_SERVER['HTTP_USER_AGENT']
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, Xml::encodeJsVar($matomoURL . '/') . "piwik.php?udsite={$idSite}&rec=1&userid={$username}&action_name={$finalActionName}&url={$finalRequestUri}{$urlTrackingSearch}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerArray);
        curl_setopt($ch, CURLOPT_REFERER, $_SERVER["HTTP_REFERER"]);
        $output = curl_exec($ch);
        curl_close($ch);

        return;
    }
}
