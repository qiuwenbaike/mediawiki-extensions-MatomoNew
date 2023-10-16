<?php

namespace MediaWiki\Extension\Matomo;

use RequestContext;

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
     * @return string
     */
    public static function addMatomo($title)
    {
        $user = RequestContext::getMain()->getUser();
        // Is Matomo disabled for bots?
        if ($user->isAllowed('bot') && self::getParameter('IgnoreBots')) {
            return '<!-- Matomo extension is disabled for bots -->';
        }

        $idSite = self::getParameter('IDSite');
        $matomoURL = self::getParameter('URL');
        $protocol = self::getParameter('Protocol');
        $endpoint = self::getParameter('Endpoint');

        // Missing configuration parameters
        if (empty($idSite) || empty($matomoURL)) {
            return '<!-- You need to set the settings for Matomo -->';
        }

        $finalActionName = self::getParameter('ActionName');
        if (self::getParameter('UsePageTitle')) {
            $finalActionName .= $title->getPrefixedText();
        }

        // Track search results
        $urlTrackingSearch = '';
        if (self::$searchTerm !== null) {
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
            $username = $user->getName();
            $finalUsername = '&uid=' . urlencode($username);
        }

        // Check if server uses https
        if ($protocol == 'auto') {
            if (
                isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] == 'on' || $_SERVER['HTTPS'] == 1) || isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https'
            ) {
                $protocol = 'https';
            } else {
                $protocol = 'http';
            }
        }

        // Prevent XSS
        $finalActionName = '&action_name=' . urlencode($finalActionName);

        // Matomo script
        $script = <<<MATOMO
		<script>!(function(){var xhr=new XMLHttpRequest();xhr.open('post',"{$protocol}://{$matomoURL}/{$endpoint}?idsite={$idSite}&rec=1&send_image=0{$finalActionName}{$finalUsername}{$urlTrackingSearch}");xhr.send()})();</script>
		<noscript><img src="{$protocol}://{$matomoURL}/{$endpoint}?idsite={$idSite}&rec=1&send_image=0{$finalActionName}{$finalUsername}{$urlTrackingSearch}" width="1" height="1" alt="" /></noscript>
		MATOMO;

        return $script;
    }
}
