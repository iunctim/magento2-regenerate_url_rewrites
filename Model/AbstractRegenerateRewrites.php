<?php
/**
 * AbstractRegenerateRewrites.php
 *
 * @package OlegKoval_RegenerateUrlRewrites
 * @author Oleg Koval <contact@olegkoval.com>
 * @copyright 2017-2067 Oleg Koval
 * @license OSL-3.0, AFL-3.0
 */

namespace OlegKoval\RegenerateUrlRewrites\Model;

use OlegKoval\RegenerateUrlRewrites\Helper\Regenerate as RegenerateHelper;
use Magento\Framework\App\ResourceConnection;
use Magento\UrlRewrite\Model\Storage\DbStorage;
use Magento\CatalogUrlRewrite\Model\ResourceModel\Category\Product as ProductUrlRewriteResource;

abstract class AbstractRegenerateRewrites
{
    /**
     * @var string
     */
    protected $entityType = 'product';

    /**
     * @var array
     */
    protected $storeRootCategoryId = [];

    /**
     * @var integer
     */
    protected $progressBarProgress = 0;

    /**
     * @var integer
     */
    protected $progressBarTotal = 0;

    /**
     * @var string
     */
    protected $mainDbTable;

    /**
     * @var string
     */
    protected $secondaryDbTable;

    /**
     * Regenerate Rewrites custom options
     * @var array
     */
    public $regenerateOptions = [];

    /**
     * @var RegenerateHelper
     */
    protected $helper;

    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * RegenerateAbstract constructor.
     * @param RegenerateHelper $helper
     */
    public function __construct(
        RegenerateHelper $helper,
        ResourceConnection $resourceConnection
    )
    {
        $this->helper = $helper;
        $this->resourceConnection = $resourceConnection;

        // set default regenerate options
        $this->regenerateOptions['saveOldUrls'] = false;
        $this->regenerateOptions['categoriesFilter'] = [];
        $this->regenerateOptions['productsFilter'] = [];
        $this->regenerateOptions['categoryId'] = null;
        $this->regenerateOptions['productId'] = null;
        $this->regenerateOptions['checkUseCategoryInProductUrl'] = false;
        $this->regenerateOptions['noRegenUrlKey'] = false;
        $this->regenerateOptions['showProgress'] = false;
    }

    /**
     * Regenerate Url Rewrites in specific store
     * @param int $storeId
     * @return mixed
     */
    abstract function regenerate($storeId = 0);

    /**
     * Return resource connection
     * @return ResourceConnection
     */
    protected function _getResourceConnection()
    {
        return $this->resourceConnection;
    }

    /**
     * Save Url Rewrites
     * @param array $urlRewrites
     * @param array $entityData
     * @return $this
     */
    public function saveUrlRewrites($urlRewrites, $entityData = [])
    {
        $data = $this->_prepareUrlRewrites($urlRewrites);

        if (!$this->regenerateOptions['saveOldUrls']) {
            if (empty($entityData) && !empty($data)) {
                $entityData = $data;
            }
            $this->_deleteCurrentRewrites($entityData);
        }

        $this->_getResourceConnection()->getConnection()->beginTransaction();
        try {
            $this->_getResourceConnection()->getConnection()->insertOnDuplicate(
                $this->_getMainTableName(),
                $data
            );
            $this->_getResourceConnection()->getConnection()->commit();

        } catch (\Exception $e) {
            $this->_getResourceConnection()->getConnection()->rollBack();
            throw $e;
        }

        return $this;
    }

    /**
     * Show a progress bar in the console
     * @return void
     */
    protected function _showProgress($size = 70)
    {
        if (!$this->regenerateOptions['showProgress']) {
            return;
        }

        // if we go over our bound, just ignore it
        if ($this->progressBarProgress > $this->progressBarTotal) {
            return;
        }

        $perc = $this->progressBarTotal ? (double)($this->progressBarProgress / $this->progressBarTotal) : 1;
        $bar = floor($perc * $size);

        $status_bar = "\r[";
        $status_bar .= str_repeat('=', $bar);
        if ($bar < $size) {
            $status_bar .= '>';
            $status_bar .= str_repeat(' ', $size - $bar);
        } else {
            $status_bar .= '=';
        }

        $disp = number_format($perc * 100, 0);

        $status_bar .= "] {$disp}%  {$this->progressBarProgress}/{$this->progressBarTotal}";

        echo $status_bar;
        flush();

        // when done, send a newline
        if ($this->progressBarProgress == $this->progressBarTotal) {
            echo "\r\n";
        }
    }

    /**
     * @return string
     */
    protected function _getMainTableName()
    {
        if (empty($this->mainDbTable)) {
            $this->mainDbTable = $this->_getResourceConnection()->getTableName(DbStorage::TABLE_NAME);
        }

        return $this->mainDbTable;
    }

    /**
     * @return string
     */
    protected function _getSecondaryTableName()
    {
        if (empty($this->secondaryDbTable)) {
            $this->secondaryDbTable = $this->_getResourceConnection()->getTableName(ProductUrlRewriteResource::TABLE_NAME);
        }

        return $this->secondaryDbTable;
    }

    /**
     * Delete current Url Rewrites
     * @param array $entitiesData
     * @return $this
     */
    protected function _deleteCurrentRewrites($entitiesData = [])
    {
        if (!empty($entitiesData)) {
            $whereConditions = [];
            foreach ($entitiesData as $entityData) {
                $whereConditions[] = sprintf(
                    '(entity_type = \'%s\' AND entity_id = %d AND store_id = %d)',
                    $entityData['entity_type'], $entityData['entity_id'], $entityData['store_id']
                );
            }
            $whereConditions = array_unique($whereConditions);

            $this->_getResourceConnection()->getConnection()->beginTransaction();
            try {
                $this->_getResourceConnection()->getConnection()->delete(
                    $this->_getMainTableName(),
                    implode(' OR ', $whereConditions)
                );
                $this->_getResourceConnection()->getConnection()->commit();

            } catch (\Exception $e) {
                $this->_getResourceConnection()->getConnection()->rollBack();
                throw $e;
            }
        }

        return $this;
    }

    /**
     * Update "catalog_url_rewrite_product_category" table
     * @return $this
     */
    protected function _updateSecondaryTable()
    {
        $this->_getResourceConnection()->getConnection()->beginTransaction();
        try {
            $this->_getResourceConnection()->getConnection()->delete(
                $this->_getSecondaryTableName(),
                "url_rewrite_id NOT IN (SELECT url_rewrite_id FROM {$this->_getMainTableName()})"
            );
            $this->_getResourceConnection()->getConnection()->commit();

        } catch (\Exception $e) {
            $this->_getResourceConnection()->getConnection()->rollBack();
        }

        $select = $this->_getResourceConnection()->getConnection()->select()
            ->from(
                $this->_getMainTableName(),
                [
                    'url_rewrite_id',
                    'category_id' => new \Zend_Db_Expr(
                        'SUBSTRING_INDEX(SUBSTRING_INDEX('.$this->_getMainTableName().'.metadata, \'"\', -2), \'"\', 1)'
                    ),
                    'product_id' =>'entity_id'
                ]
            )
            ->where('metadata LIKE \'{"category_id":"%"}\'')
            ->where("url_rewrite_id NOT IN (SELECT url_rewrite_id FROM {$this->_getSecondaryTableName()})");
        $data = $this->_getResourceConnection()->getConnection()->fetchAll($select);

        if (!empty($data)) {
            $this->_getResourceConnection()->getConnection()->beginTransaction();
            try {
                $this->_getResourceConnection()->getConnection()->insertOnDuplicate(
                    $this->_getSecondaryTableName(),
                    $data
                );
                $this->_getResourceConnection()->getConnection()->commit();

            } catch (\Exception $e) {
                $this->_getResourceConnection()->getConnection()->rollBack();
                throw $e;
            }
        }

        return $this;
    }

    /**
     * @param array $urlRewrites
     * @return array
     */
    protected function _prepareUrlRewrites($urlRewrites)
    {
        $result = [];
        foreach ($urlRewrites as $urlRewrite) {
            $rewrite = $urlRewrite->toArray();

            // check if same Url Rewrite already exists
            $originalRequestPath = $rewrite['request_path'];
            $index = 0;
            while ($this->_urlRewriteExists($rewrite)) {
                $index++;
                $pathParts = pathinfo($originalRequestPath);
                $rewrite['request_path'] = $pathParts['dirname'] . $pathParts['filename'] . '-'. $index . (!empty($pathParts['extension']) ? $pathParts['extension'] : '');
            }

            $result[] = $rewrite;
        }

        return $result;
    }

    /**
     * Check if Url Rewrite with same request path exists
     * @param array $rewrite
     * @return bool
     */
    protected function _urlRewriteExists($rewrite)
    {
        $select = $this->_getResourceConnection()->getConnection()->select()
            ->from($this->_getMainTableName(), ['url_rewrite_id'])
            ->where('entity_type = ?', $rewrite['entity_type'])
            ->where('request_path = ?', $rewrite['request_path'])
            ->where('store_id = ?', $rewrite['store_id'])
            ->where('entity_id != ?', $rewrite['entity_id']);
        return $this->_getResourceConnection()->getConnection()->fetchOne($select);
    }

    /**
     * Get root category Id of specific store
     * @param $storeId
     * @return mixed
     */
    protected function _getStoreRootCategoryId($storeId)
    {
        if (empty($this->storeRootCategoryId[$storeId])) {
            $this->storeRootCategoryId[$storeId] = null;
            $store = $this->helper->getStoreManager()->getStore($storeId);
            if ($store) {
                $this->storeRootCategoryId[$storeId] = $store->getRootCategoryId();
            }
        }

        return $this->storeRootCategoryId[$storeId];
    }
}