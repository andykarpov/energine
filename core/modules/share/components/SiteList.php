<?php
/**
 * @file
 * SiteList
 *
 * It contains the definition to:
 * @code
class SiteList;
@endcode
 *
 * @author d.pavka
 * @copyright d.pavka@gmail.com
 *
 * @version 1.0.0
 */
namespace Energine\share\components;
use Energine\share\gears\SimpleBuilder;
use Energine\share\gears\TagManager;
/**
 * Site list.
 *
 * @code
class SiteList;
@endcode
 */
class SiteList extends DataSet {
    /**
     * @copydoc DataSet::__construct
     */
    public function __construct($name,  array $params = null) {
        parent::__construct($name, $params);
        $this->setType(self::COMPONENT_TYPE_LIST);
        $this->setBuilder(new SimpleBuilder());
    }

    /**
     * @copydoc DataSet::defineParams
     */
    // Добавлены теги, количество принудительно сброшено
    protected function defineParams() {
        $result = array_merge(parent::defineParams(),
            [
                'tags' => '',
                'recordsPerPage' => false,
                'site' => false
            ]);
        return $result;
    }

    /**
     * @copydoc DataSet::loadData
     */
    // Загружаем данные SiteManager
    protected function loadData() {
        $result = [];
        $filteredIDs = true;

        if ($this->getParam('tags'))
            $filteredIDs = TagManager::getFilter($this->getParam('tags'), 'share_sites');

        if (!empty($filteredIDs))
            foreach (E()->SiteManager as $siteID => $site) {
                if (
                    ($filteredIDs !== true) && in_array($siteID, $filteredIDs)
                    ||
                    ($filteredIDs === true)
                ) {
                    $result[] = [
                        'site_id' => $site->id,
                        'site_name' => $site->name,
                        'site_host' => $site->protocol . '://' . $site->host . (($site->port != 80) ? ':' . $site->port : '') . $site->root
                    ];
                }
            }
        return $result;
    }
}