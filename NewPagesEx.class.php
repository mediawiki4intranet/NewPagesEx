<?php

/* NewPagesEx extension class, based on original MediaWiki's SpecialNewpages.php
 * Copyright (c) 2011, Vitaliy Filippov <vitalif[d.o.g]mail.ru>
 * License: GPLv3.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * implements Special:NewPages
 * @ingroup SpecialPage
 */
class SpecialNewPagesEx extends SpecialPage
{
    var $opts, $skin, $pager;
    var $showNavigation = false;

    // Default item format
    static $format = '$date $time $dm$plink ($hist) $dm[$length] $dm$ulink $utlink $comment $ctags';

    public function __construct()
    {
        parent::__construct('NewPages');
        $this->includable(true);
        wfLoadExtensionMessages('NewPagesEx');
    }

    // Parse options
    protected function setup($par)
    {
        global $wgRequest, $wgUser, $wgEnableNewpagesUserFilter;

        $opts = new FormOptions();
        $this->opts = $opts;
        $opts->add('hideliu', false);
        $opts->add('hidepatrolled', $wgUser->getBoolOption('newpageshidepatrolled'));
        $opts->add('hidebots', false);
        $opts->add('hideredirs', true);
        $opts->add('limit', (int)$wgUser->getOption('rclimit'));
        $opts->add('offset', '');
        $opts->add('namespace', '0');
        $opts->add('username', '');
        $opts->add('category', '');
        $opts->add('feed', '');
        $opts->add('tagfilter', '');
        $opts->add('format', self::$format);

        // Set values
        $opts->fetchValuesFromRequest($wgRequest);
        if ($par)
            $this->parseParams($par);

        $this->pager = new NewPagesExPager($this, $this->opts);
        $this->pager->mLimit = $this->opts->getValue('limit');
        $this->pager->mOffset = $this->opts->getValue('offset');
        $this->pager->getQueryInfo();

        // Validate
        $opts->validateIntBounds('limit', 0, 5000);
        if (!$wgEnableNewpagesUserFilter)
            $opts->setValue('username', '');

        // Store some objects
        $this->skin = $wgUser->getSkin();
    }

    // Parse parameters passed as special page subpage
    protected function parseParams($par)
    {
        global $wgLang;
        $bits = preg_match_all(
            '/(shownav|hide(?:liu|patrolled|bots|redirs))|'.
            '(limit|offset|username|category|namespace|format)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^,]+))/is',
            $par, $m, PREG_SET_ORDER);
        foreach ($m as $bit)
        {
            if ($bit[1] == 'shownav')
                $this->showNavigation = true;
            elseif ($bit[1])
                $this->opts->setValue($bit[1], true);
            elseif ($bit[2] == 'namespace')
            {
                $ns = $wgLang->getNsIndex($bit[3] ? $bit[3] : ($bit[4] ? $bit[4] : $bit[5]));
                if($ns !== false)
                    $this->opts->setValue('namespace', $ns);
            }
            else
                $this->opts->setValue($bit[2], $bit[5] ? $bit[5] : str_replace('_', ' ', $bit[3] ? $bit[3] : $bit[4]));
        }
    }

    /**
     * Show a form for filtering namespace and username
     *
     * @param string $par
     * @return string
     */
    public function execute($par)
    {
        global $wgLang, $wgOut;

        $this->setHeaders();
        $this->outputHeader();

        $this->showNavigation = !$this->including(); // Maybe changed in setup
        $this->setup($par);

        if (!$this->including())
        {
            // Settings
            $this->form();

            $this->setSyndicated();
            $feedType = $this->opts->getValue('feed');
            if ($feedType)
                return $this->feed($feedType);
        }

        if ($this->pager->getNumRows())
        {
            $navigation = '';
            if ($this->showNavigation)
                $navigation = $this->pager->getNavigationBar();
            $wgOut->addHTML($navigation . $this->pager->getBody() . $navigation);
        }
        else
            $wgOut->addWikiMsg('specialpage-empty');
    }

    protected function filterLinks()
    {
        global $wgGroupPermissions, $wgUser, $wgLang;

        // show/hide links
        $showhide = array(wfMsgHtml('show'), wfMsgHtml('hide'));

        // Option value -> message mapping
        $filters = array(
            'hideliu'       => 'rcshowhideliu',
            'hidepatrolled' => 'rcshowhidepatr',
            'hidebots'      => 'rcshowhidebots',
            'hideredirs'    => 'whatlinkshere-hideredirs'
        );

        // Disable some if needed
        if ($wgGroupPermissions['*']['createpage'] !== true)
            unset($filters['hideliu']);
        if (!$wgUser->useNPPatrol())
            unset($filters['hidepatrolled']);

        $links = array();
        $changed = $this->opts->getChangedValues();
        unset($changed['offset']); // Reset offset if query type changes

        $self = $this->getTitle();
        foreach ($filters as $key => $msg)
        {
            $onoff = !$this->opts->getValue($key);
            $link = $this->skin->link(
                $self, $showhide[$onoff], array(),
                array($key => $onoff) + $changed
            );
            $links[$key] = wfMsgHtml($msg, $link);
        }

        return $wgLang->pipeList($links);
    }

    protected function form()
    {
        global $wgOut, $wgEnableNewpagesUserFilter, $wgDisableNewpagesCategoryFilter, $wgScript;

        // Consume values
        $this->opts->consumeValue('offset'); // don't carry offset, DWIW
        $namespace = $this->opts->consumeValue('namespace');
        $username = $this->opts->consumeValue('username');
        $tagFilterVal = $this->opts->consumeValue('tagfilter');

        // Check username input validity
        $ut = Title::makeTitleSafe(NS_USER, $username);
        $userText = $ut ? $ut->getText() : '';

        $category = $this->opts->consumeValue('category');

        // Store query values in hidden fields so that form submission doesn't lose them
        $hidden = array();
        foreach ($this->opts->getUnconsumedValues() as $key => $value)
            $hidden[] = Xml::hidden($key, $value);
        $hidden = implode("\n", $hidden);

        $tagFilter = ChangeTags::buildTagFilterSelector($tagFilterVal);

        // Field array is (label, field, label, field, ...)
        $fields = array();
        $fields[] = Xml::label(wfMsg('namespace'), 'namespace');
        $fields[] = Xml::namespaceSelector($namespace, 'all');
        if ($tagFilter)
            $fields = array_merge($fields, $tagFilter);
        if ($wgEnableNewpagesUserFilter)
        {
            $fields[] = Xml::label(wfMsg('newpages-username'), 'mw-np-username');
            $fields[] = Xml::input('username', 30, $userText, array('id' => 'mw-np-username'));
        }
        if (!$wgDisableNewpagesCategoryFilter)
        {
            $fields[] = Xml::label(wfMsg('newpages-category'), 'mw-np-category');
            $attr = array('id' => 'mw-np-category');
            if ($category !== "" && !$this->pager->mCategory)
                $attr['style'] = 'background-color: #ffe0e0';
            $fields[] = Xml::input('category', 30, $category, $attr);
        }
        $fields[] = '';
        $fields[] = Xml::submitButton(wfMsg('allpagessubmit'));
        $fields[] = '';
        $fields[] = $this->filterLinks();

        // Generate HTML code
        $form = '';
        for ($i = 0; $i < count($fields); $i += 2)
            $form .= '<tr><td class="mw-label">'.$fields[$i].'</td><td class="mw-input">'.$fields[$i+1].'</td></tr>';

        $form = Xml::openElement('form', array('action' => $wgScript)) .
            Xml::hidden('title', $this->getTitle()->getPrefixedDBkey()) .
            Xml::fieldset(wfMsg('newpages')) .
            "<table id='mw-newpages-table'>$form</table></fieldset>$hidden</form>";

        $wgOut->addHTML($form);
    }

    protected function setSyndicated()
    {
        global $wgOut;
        $wgOut->setSyndicated(true);
        $wgOut->setFeedAppendQuery(wfArrayToCGI($this->opts->getAllValues()));
    }

    /**
     * Format a row, providing the timestamp, links to the page/history, size, user links, and a comment
     *
     * @param $skin Skin to use
     * @param $result Result row
     * @return string
     */
    public function formatRow($result)
    {
        global $wgLang, $wgContLang;

        $title = Title::makeTitleSafe($result->rc_namespace, $result->rc_title);
        /* HaloACL/IntraACL support */
        if (method_exists($title, 'userCanReadEx') && !$title->userCanReadEx())
            return '';

        $dm = $wgContLang->getDirMark();
        $length = wfMsgExt('nbytes', array('parsemag', 'escape'), $wgLang->formatNum($result->length));
        $comment = $this->skin->commentBlock($result->rc_comment);

        $query = array('redirect' => 'no');
        $classes = array();

        if ($this->patrollable($result))
        {
            $query['rcid'] = $result->rc_id;
            $classes[] = 'not-patrolled';
        }

        // RC tags, if any.
        list($tagDisplay, $newClasses) = ChangeTags::formatSummaryRow($result->ts_tags, 'newpages');
        $classes = array_merge($classes, $newClasses);

        $params = array(
            '$dm'       => $dm,
            '$date'     => htmlspecialchars($wgLang->date($result->rc_timestamp, true)),
            '$time'     => htmlspecialchars($wgLang->time($result->rc_timestamp, true)),
            '$plink'    => $this->skin->linkKnown($title, null, array(), $query),
            '$ulink'    => $this->skin->userLink($result->rc_user, $result->rc_user_text),
            '$utlink'   => $this->skin->userToolLinks($result->rc_user, $result->rc_user_text),
            '$hist'     => $this->skin->linkKnown($title, wfMsgHtml('hist'), array(), array('action' => 'history')),
            '$length'   => $length,
            '$comment'  => $comment,
            '$ctags'    => $tagDisplay,
        );

        $css = count($classes) ? ' class="'.implode(" ", $classes).'"' : '';

        return "<li$css>".str_replace(
            array_keys($params),
            array_values($params),
            $this->opts->getValue('format')
        )."</li>\n";
    }

    /**
     * Should a specific result row provide "patrollable" links?
     *
     * @param $result Result row
     * @return bool
     */
    protected function patrollable($result)
    {
        global $wgUser;
        return ($wgUser->useNPPatrol() && !$result->rc_patrolled);
    }

    /**
     * Output a cached Atom/RSS feed with new page listing.
     * @param string $type
     */
    protected function feed($type)
    {
        global $wgFeed, $wgFeedClasses, $wgFeedLimit, $wgUser, $wgLang, $wgRequest;

        if (!$wgFeed)
        {
            global $wgOut;
            $wgOut->addWikiMsg('feed-unavailable');
            return;
        }

        if (!isset($wgFeedClasses[$type]))
        {
            global $wgOut;
            $wgOut->addWikiMsg('feed-invalid');
            return;
        }

        // Check modification time
        $limit = $this->opts->getValue('limit');
        $this->pager->mLimit = min($limit, $wgFeedLimit);
        $lastmod = $this->pager->lastModifiedTime();

        $userid = $wgUser->getId();
        $optionsHash = md5(serialize($this->opts->getAllValues()));
        $timekey = wfMemcKey('npfeed', $userid, $optionsHash, 'timestamp');
        $key = wfMemcKey('npfeed', $userid, $wgLang->getCode(), $optionsHash);

        // Check for ?action=purge
        FeedUtils::checkPurge($timekey, $key);

        $feed = new $wgFeedClasses[$type](
            $this->feedTitle(),
            wfMsgExt('tagline', 'parsemag'),
            $this->getTitle()->getFullUrl());

        // Check if the cached feed exists
        $cachedFeed = $this->loadFromCache($lastmod, $timekey, $key);
        if (is_string($cachedFeed))
        {
            wfDebug("NewPagesEx: Outputting cached feed\n");
            $feed->httpHeaders();
            echo $cachedFeed;
        }
        else
        {
            wfDebug("NewPagesEx: rendering new feed and caching it\n");
            ob_start();
            $this->generateFeed($this->pager, $feed);
            $cachedFeed = ob_get_contents();
            ob_end_flush();
            $this->saveToCache($cachedFeed, $timekey, $key);
        }
    }

    public function generateFeed($pager, $feed)
    {
        $feed->outHeader();
        if ($pager->getNumRows() > 0)
            while($row = $pager->mResult->fetchObject())
                $feed->outItem($this->feedItem($row));
        $feed->outFooter();
    }

    public function loadFromCache($lastmod, $timekey, $key)
    {
        global $wgFeedCacheTimeout, $messageMemc;
        $feedLastmod = $messageMemc->get($timekey);

        if(($wgFeedCacheTimeout > 0) && $feedLastmod) {
            /*
            * If the cached feed was rendered very recently, we may
            * go ahead and use it even if there have been edits made
            * since it was rendered. This keeps a swarm of requests
            * from being too bad on a super-frequently edited wiki.
            */

            $feedAge = time() - wfTimestamp(TS_UNIX, $feedLastmod);
            $feedLastmodUnix = wfTimestamp(TS_UNIX, $feedLastmod);
            $lastmodUnix = wfTimestamp(TS_UNIX, $lastmod);

            if($feedAge < $wgFeedCacheTimeout || $feedLastmodUnix > $lastmodUnix) {
                wfDebug("NewPagesEx: loading feed from cache ($key; $feedLastmod; $lastmod)...\n");
                return $messageMemc->get($key);
            } else {
                wfDebug("NewPagesEx: cached feed timestamp check failed ($feedLastmod; $lastmod)\n");
            }
        }
        return false;
    }

    public function saveToCache($feed, $timekey, $key)
    {
        global $messageMemc;
        $expire = 3600 * 24; # One day
        $messageMemc->set($key, $feed, $expire);
        $messageMemc->set($timekey, wfTimestamp(TS_MW), $expire);
    }

    protected function feedTitle()
    {
        global $wgContLanguageCode, $wgSitename;
        $page = SpecialPage::getPage('Newpages');
        $desc = $page->getDescription();
        return "$wgSitename - $desc [$wgContLanguageCode]";
    }

    protected function feedItem($row)
    {
        $title = Title::MakeTitle(intval($row->rc_namespace), $row->rc_title);
/*patch|2011-05-12|IntraACL|start*/
        if($title && (!method_exists($title, 'userCanReadEx') ||
            $title->userCanReadEx()))
/*patch|2011-05-12|IntraACL|end*/
        {
            $date = $row->rc_timestamp;
            $comments = $title->getTalkPage()->getFullURL();

            return new FeedItem(
                $title->getPrefixedText(),
                $this->feedItemDesc($row),
                $title->getFullURL(),
                $date,
                $this->feedItemAuthor($row),
                $comments);
        } else {
            return null;
        }
    }

    protected function feedItemAuthor($row)
    {
        return isset($row->rc_user_text) ? $row->rc_user_text : '';
    }

    protected function feedItemDesc($row)
    {
        global $wgNewpagesFeedNoHtml, $wgUser, $wgParser;
        $revision = Revision::newFromId($row->rev_id);
        if($revision)
        {
            $t = $revision->getText();
            if ($wgNewpagesFeedNoHtml)
                $t = nl2br(htmlspecialchars($t));
            else
            {
                if (!$this->parserOptions)
                {
                    $this->parserOptions = ParserOptions::newFromUser($wgUser);
                    $this->parserOptions->setEditSection(false);
                }
                $t = $wgParser->getSection($t, 0);
                $t = $wgParser->parse($t, $revision->getTitle(), $this->parserOptions);
                $t = $t->getText();
            }
            return '<p>' . htmlspecialchars($revision->getUserText()) . wfMsgForContent('colon-separator') .
                htmlspecialchars(FeedItem::stripComment($revision->getComment())) .
                "</p>\n<hr />\n<div>" .
                $t . "</div>";
        }
        return '';
    }
}

/**
 * @ingroup SpecialPage Pager
 */
class NewPagesExPager extends ReverseChronologicalPager
{
    // Saved options and SpecialPage
    var $opts, $mForm;
    // Cached query info and title
    var $mQueryInfo, $mTitle;
    // Checked category, namespace, user objects
    var $mCategory, $mNamespace, $mUser;

    function __construct($form, FormOptions $opts)
    {
        parent::__construct();
        $this->mForm = $form;
        $this->opts = $opts;
    }

    function getTitle()
    {
        if (!$this->mTitle)
            $this->mTitle = $this->mForm->getTitle();
        return $this->mTitle;
    }

    function getQueryInfo()
    {
        // Evaluate query options once
        if ($this->mQueryInfo)
            return $this->mQueryInfo;

        global $wgEnableNewpagesUserFilter, $wgGroupPermissions, $wgUser;
        $conds = array();
        $conds['rc_new'] = 1;

        // Check namespace index
        $this->mNamespace = $this->opts->getValue('namespace');
        $this->mNamespace = ($this->mNamespace === 'all') ? false : intval($this->mNamespace);

        // Check username
        $username = $this->opts->getValue('username');
        $this->mUser = Title::makeTitleSafe(NS_USER, $username);

        // Check category
        $category = $this->opts->getValue('category');
        $categoryTitle = $category ? Title::newFromText($category, NS_CATEGORY) : NULL;
        // Recheck namespace of created title, this also supports HaloACL/IntraACL
        $this->mCategory = $categoryTitle && $categoryTitle->exists() &&
            $categoryTitle->getNamespace() == NS_CATEGORY ? $categoryTitle : NULL;

        if ($this->mNamespace !== false)
        {
            $conds['rc_namespace'] = $this->mNamespace;
            $rcIndexes = array('new_name_timestamp');
        }
        else
            $rcIndexes = array('rc_timestamp');

        if ($wgEnableNewpagesUserFilter && $this->mUser)
        {
            $conds['rc_user_text'] = $this->mUser->getText();
            $rcIndexes = 'rc_user_text';
        }
        // If anons cannot make new pages, don't "exclude logged in users"!
        elseif ($wgGroupPermissions['*']['createpage'] && $this->opts->getValue('hideliu'))
            $conds['rc_user'] = 0;

        // If this user cannot see patrolled edits or they are off, don't do dumb queries!
        if ($this->opts->getValue('hidepatrolled') && $wgUser->useNPPatrol())
            $conds['rc_patrolled'] = 0;
        if ($this->opts->getValue('hidebots'))
            $conds['rc_bot'] = 0;

        if ($this->opts->getValue('hideredirs'))
            $conds['page_is_redirect'] = 0;

        $info = array(
            'tables'    => array('recentchanges', 'page'),
            'fields'    => 'recentchanges.*, page_len as length, page_latest as rev_id, ts_tags',
            'conds'     => $conds,
            'options'   => array('USE INDEX' => array('recentchanges' => $rcIndexes)),
            'join_conds' => array(
                'page'  => array('INNER JOIN', 'page_id=rc_cur_id'),
            ),
        );

        if ($this->mCategory !== NULL)
        {
            $info['tables'][] = 'categorylinks';
            $info['join_conds']['categorylinks'] = array('INNER JOIN', 'cl_from = page_id');
            $info['conds']['cl_to'] = $this->mCategory->getDBkey();
        }

        // Empty array for fields, it'll be set by us anyway.
        $fields = array();

        // Modify query for change tags
        ChangeTags::modifyDisplayQuery($info['tables'],
                                        $fields,
                                        $info['conds'],
                                        $info['join_conds'],
                                        $info['options'],
                                        $this->opts['tagfilter']);

        return $this->mQueryInfo = $info;
    }

    // Get modification timestamp
    function lastModifiedTime()
    {
        $mtimequery = $this->getQueryInfo();
        $dbr = wfGetDB(DB_SLAVE);
        $res = $dbr->select(
            $mtimequery['tables'], 'MAX(rc_timestamp)',
            $mtimequery['conds'], __FUNCTION__,
            $mtimequery['options'], $mtimequery['join_conds']
        );
        $lastmod = $res->fetchRow();
        $lastmod = $lastmod[0];
        return $lastmod;
    }

    function getIndexField()
    {
        return 'rc_timestamp';
    }

    function formatRow($row)
    {
        return $this->mForm->formatRow($row);
    }

    function getStartBody()
    {
        // Do a batch existence check on pages
        $linkBatch = new LinkBatch();
        while($row = $this->mResult->fetchObject()) {
            $linkBatch->add(NS_USER, $row->rc_user_text);
            $linkBatch->add(NS_USER_TALK, $row->rc_user_text);
            $linkBatch->add($row->rc_namespace, $row->rc_title);
        }
        $linkBatch->execute();
        return "<ul>";
    }

    function getEndBody()
    {
        return "</ul>";
    }
}
