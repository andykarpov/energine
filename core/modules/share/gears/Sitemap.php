<?php
/**
 * @file
 * Sitemap.
 *
 * It contains the definition to:
 * @code
final class Sitemap;
 * @endcode
 *
 * @author dr.Pavka
 * @copyright Energine 2006
 */

namespace Energine\share\gears;
/**
 * Site map.
 *
 * It contain the methods for work with site structure.
 *
 * @code
final class Sitemap;
 * @endcode
 *
 * @attention This is singleton class.
 * @final
 */
final class Sitemap extends Primitive {
    use DBWorker;
    /**
     * Class exemplar that works with tree structures.
     * @var TreeNodeList $tree
     */
    private $tree;

    /**
     * Information about sections where the user can access.
     * @var array $info
     */
    private $info = [];

    /**
     * Default page ID.
     * This variable was created to minimize the using of requests.
     * @var int $defaultID
     */
    private $defaultID = false;

    /**
     * Default Meta-Keywords.
     * This is used for all pages that haven't custom Meta-keyword.
     * This variable was created to minimize the using of requests.
     *
     * @var string $defaultMetaKeywords
     */
    private $defaultMetaKeywords;

    /**
     * Default Meta-Description.
     * @var string $defaultMetaDescription
     *
     * @see Site::MetaKeywords
     */
    private $defaultMetaDescription;
    /**
     * Default Meta Robots
     * @var array $defaultMetaRobots
     *
     * @see Site::MetaKeywords
     */
    private $defaultMetaRobots;

    /**
     * Current language ID.
     * @var int $langID
     */
    private $langID;

    /**
     * Cache of access levels.
     * @var array $cacheAccessLevels
     */
    private $cacheAccessLevels = [];

    /**
     * Current site ID.
     * @var int $siteID
     */
    private $siteID;

    /**
     * @param int $siteID Site ID.
     *
     * @throws SystemException 'ERR_NO_TRANSLATION'
     * @throws SystemException 'ERR_404'
     */
    public function __construct($siteID) {
        parent::__construct();
        $this->siteID = $siteID;
        $this->langID = E()->getLanguage()->getCurrent();
        $userGroups = array_keys(E()->UserGroup->asArray());

        //Загружаем идентификаторы для последующего формирования древовидной стркутуры

        $res = $this->dbh->select(
            'SELECT s.smap_id, s.smap_pid FROM share_sitemap s ' .
            'LEFT JOIN share_sitemap_translation st ON st.smap_id = s.smap_id ' .
            'WHERE st.smap_is_disabled = 0 AND s.site_id = %s AND st.lang_id = %s ' .
            'AND s.smap_id IN( ' .
            ' SELECT smap_id ' .
            ' FROM share_access_level ' .
            ' WHERE group_id IN (' . implode(',', E()->getUser()->getGroups()) . ')) ' .
            'ORDER BY smap_order_num',
            $this->siteID,
            $this->langID
        );
        //@todo Нужно бы накладывать ограничение в подзапросе на сайт, не факт правда что это увеличит быстродействие

        /*
        SELECT s.smap_id, s.smap_pid FROM share_sitemap s LEFT JOIN share_sitemap_translation st ON st.smap_id = s.smap_id WHERE st.smap_is_disabled = 0 AND s.site_id = '6' AND st.lang_id = '1' AND s.smap_id IN(
        SELECT a.smap_id
        FROM share_access_level  a
        LEFT JOIN share_sitemap s USING(smap_id)
        WHERE group_id IN (1) AND s.site_id=6
        ) ORDER BY smap_order_num
        */

        if (empty($res)) {
            return;
            throw new SystemException('ERR_NO_TRANSLATION', SystemException::ERR_CRITICAL, $this->dbh->getLastRequest());
        }
        //Кешируем уровни доступа к страницам сайта
        //Формируем матрицу вида
        //[идентификатор раздела][идентификатор роли] = идентификатор уровня доступа
        $rightsMatrix = $this->dbh->select('share_access_level', true, ['smap_id' => array_map(create_function('$a', 'return $a["smap_id"];'), $res)]);

        if (!$rightsMatrix) {
            throw new SystemException('ERR_404', SystemException::ERR_404);
        }

        foreach ($rightsMatrix as $data) {
            foreach ($userGroups as $groupID) {
                //todo проверить вариант с пересечением array_diff
                if (!isset($this->cacheAccessLevels[$data['smap_id']][$groupID]))
                    $this->cacheAccessLevels[$data['smap_id']][$groupID] = ACCESS_NONE;
            }
            $this->cacheAccessLevels[$data['smap_id']][$data['group_id']] = (int)$data['right_id'];
        }

        //Загружаем перечень идентификаторов в объект дерева
        $this->tree = TreeConverter::convert($res, 'smap_id', 'smap_pid');
        $site = E()->getSiteManager()->getSiteByID($this->siteID);
        $res = $this->dbh->select('
		  SELECT s.smap_id FROM share_sitemap s
            WHERE s.site_id = %s AND s.smap_pid IS NULL
		', $this->siteID);
        list($res) = $res;
        $this->defaultID = $res['smap_id'];

        $this->defaultMetaKeywords = $site->metaKeywords;
        $this->defaultMetaDescription = $site->metaDescription;
        $this->defaultMetaRobots = $site->metaRobots;

        $this->getSitemapData(array_keys($this->tree->asList()));
    }

    /**
     * Get site ID by page ID.
     *
     * @param int $pageID Page ID.
     * @return mixed
     */
    public static function getSiteID($pageID) {
        return E()->getDB()->getScalar('share_sitemap', 'site_id', ['smap_id' => (int)$pageID]);
    }

    /**
     * Get information about sections.
     *
     * @param int|array $id Section ID or array of IDs.
     * @return array
     */
    private function getSitemapData($id) {
        if (!is_array($id)) {
            $id = [$id];
        }

        if ($diff = array_diff($id, array_keys($this->info))) {
            $ids = implode(',', $diff);
            $result = convertDBResult(
                $this->dbh->select(
                    'SELECT s.*, st.*
	                    FROM share_sitemap s
	                    LEFT JOIN share_sitemap_translation st ON s.smap_id = st.smap_id
	                    WHERE st.lang_id = %s AND s.site_id = %s AND s.smap_id IN (' .
                    $ids . ')',
                    $this->langID,
                    $this->siteID
                ),
                'smap_id', true);

            if ($result) {
                $result = array_map([$this, 'preparePageInfo'], $result);
                $this->info += $result;
            }

        } else {
            $result = [];
            foreach ($this->info as $key => $value) {
                if (in_array($key, $diff))
                    $result[$key] = $value;

            }
        }
        return $result;
    }

    /**
     * @param string $tag
     * @return array
     */
    public function getPagesByTag($tag) {
        return $this->dbh->getColumn('SELECT *
        FROM `share_sitemap_tags` st
        RIGHT JOIN share_sitemap s On (st.smap_id = s.smap_id) AND (s.site_id= %s)
        WHERE tag_id IN (%s)', $this->siteID, array_keys(TagManager::getID($tag)));
    }


    /**
     * Prepare page information.
     *
     * This is internal method for transforming information about document.
     * It set all keys to @c camelNotation and change template ID for link.
     *
     * @param array $current Current page.
     * @return array
     */
    private function preparePageInfo($current) {
        //здесь что то лишнее
        //@todo А нужно ли вообще обрабатывать все разделы?
        $result = convertFieldNames($current, 'smap');
        $result['Site'] = $result['siteId'];
        unset($result['siteId'], $result['OrderNum'], $result['langId'], $result['IsDisabled']);
        if(!empty($result['MetaRobots'])){
            $result['MetaRobots'] = explode(',', $result['MetaRobots']);
        }

        foreach(['MetaKeywords', 'MetaDescription', 'MetaRobots'] as $default){
            if(is_null($result[$default])){
                $result[$default] = $this->{'default'.$default};
            }
        }

        return $result;
    }


    /**
     * Get default page ID.
     *
     * @return int
     */
    public function getDefault() {
        return $this->defaultID;
    }

    /**
     * Get URL section by page ID.
     *
     * @param int $smapID Page ID.
     * @return string
     */
    public function getURLByID($smapID) {
        $result = [];
        $node = $this->tree->getNodeById($smapID);
        if (!is_null($node)) {
            $parents = array_reverse(array_keys($node->getParents()->asList(false)));
            foreach ($parents as $id) {
                if (isset($this->info[$id]) && $this->info[$id]['Segment']) {
                    $result[] = $this->info[$id]['Segment'];
                } else {
                    $res = $this->getDocumentInfo($id, false);
                    $result[] = $res['Segment'];
                }
            }
        }

        $currentSegment = $this->getDocumentInfo($smapID);
        $currentSegment = $currentSegment['Segment'];
        $result[] = $currentSegment;
        $result = array_filter($result);
        if (!empty($result))
            $result = implode('/', $result) . '/';
        else {
            $result = '';
        }
        return $result;
    }

    /**
     * Get page ID by his URL.
     *
     * @param array $segments URL.
     * @return int
     */
    public function getIDByURI(array $segments) {
        $request = E()->getRequest();
        $id = $this->getDefault();
        if (empty($segments)) {
            return $id;
        }

        foreach ($segments as $key => $segment) {
            $found = false;
            foreach ($this->info as $pageID => $pageInfo) {
                if (($segment == $pageInfo['Segment']) && ($id == $pageInfo['Pid'])) {
                    $id = $pageID;
                    $request->setPathOffset($key + 1);
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                break;
            }
        }
        //return ($id != $this->getDefault())?$id:false;
        return $id;
    }

    /**
     * Get document rights.
     * It also defines the set of rights for a page.
     *
     * @param int $docID Document ID.
     * @param mixed $groups Group/set of groups. If this is not defined the group of current user will be used.
     * @return int
     */
    public function getDocumentRights($docID, $groups = false) {
        if (!$groups) {
            $groups = E()->getUser()->getGroups();
        } elseif (!is_array($groups)) {
            $groups = [$groups];
        }

        $groups = array_combine($groups, $groups);

        $result = 0;
        if (isset($this->cacheAccessLevels[$docID])) {
            $result = max(array_intersect_key($this->cacheAccessLevels[$docID], $groups));
        }

        return $result;
    }

    /**
     * Get all child sections.
     *
     * @param int $smapID Section ID.
     * @param bool $returnAsTreeNodeList Return all as TreeNodeList?
     * @return array
     */
    public function getChilds($smapID, $returnAsTreeNodeList = false) {
        $result = [];
        if ($node = $this->tree->getNodeById($smapID)) {
            if (!$returnAsTreeNodeList) {
                $result = $this->buildPagesMap(array_keys($node->getChildren()->asList(false)));
            } else {
                $result = $node->getChildren();
            }
        }
        return $result;
    }

    /**
     * Get all descendants.
     *
     * @param int $smapID Section ID.
     * @return array
     */
    public function getDescendants($smapID) {
        $result = [];
        if ($node = $this->tree->getNodeById($smapID)) {
            $result = $this->buildPagesMap(array_keys($node->getChildren()->asList()));
        }
        return $result;
    }

    /**
     * Get parent.
     *
     * @param int $smapID Section ID.
     * @return int
     */
    public function getParent($smapID) {
        $node = $this->tree->getNodeById($smapID);
        $result = false;
        if (!is_null($node)) {
            $result = key($node->getParents()->asList(false));
        }

        return $result;
    }

    /**
     * Get parents.
     *
     * @param int $smapID Section ID.
     * @return array
     */
    public function getParents($smapID) {
        $node = $this->tree->getNodeById($smapID);
        $result = [];
        if (!is_null($node)) {
            $result = $this->buildPagesMap(array_reverse(array_keys($node->getParents()->asList(false))));
        }
        return $result;
    }

    /**
     * Build page map.
     * The returned array looks as follows:
     * @code array('$section_id'=>array()) @endcode
     *
     * @param array $ids Section IDs.
     * @return array
     */
    private function buildPagesMap($ids) {
        $result = [];
        if (is_array($ids)) {
            foreach ($ids as $id) {
                $info = $this->getDocumentInfo($id);
                $info['Segment'] = $this->getURLByID($id);
                $result[$id] = $info;
            }
        }

        return $result;
    }

    /**
     * Get document information.
     *
     * @param int $id Section ID
     * @return array
     */
    public function getDocumentInfo($id) {
        // Ищем документ с нужным идентификатором в $this->info
        if (isset($this->info[$id]))
            $result = $this->info[$id];
        else {
            $result = $this->getSitemapData($id);
            $result = $result[$id];
        }
        return $result;
    }


    /**
     * Get Tree object.
     *
     * @return TreeNodeList
     */
    public function getTree() {
        return $this->tree;
    }

    /**
     * Get the whole information about sections in unstructured view.
     *
     * @return array
     */
    public function getInfo() {
        return $this->info;
    }
}

