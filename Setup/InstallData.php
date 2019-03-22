<?php
/**
 * @category   Shipping
 * @package    Eniture_FedExSmallPackages
 * @author     Eniture Technology : <sales@eniture.com>
 * @website    http://eniture.com
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
 
namespace Eniture\FedExSmallPackages\Setup;
 
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\DB\Ddl\Table;
 
/**
 * @codeCoverageIgnore
 */
class InstallData implements InstallDataInterface
{
    /**
     * EAV setup factory
     *
     * @var EavSetupFactory
     */
    private $eavSetupFactory;
    /**
     * @var Tables to use
     */
    private $tableNames;
    /**
     * @var Attributes to create
     */
    private $attrNames;
    /**
     * @var DB Connection
     */
    private $_connection;
    /**
     * @var Magento Version
     */
    private $mageVersion;
 
    protected $collectionFactory;
    
    protected $_productloader;
    
    protected $_productMetadata;
    
    protected $_resource;
    protected $_enModuleFactory;
    
    /**
     * 
     * @param EavSetupFactory $eavSetupFactory
     * @param \Magento\Framework\App\State $state
     * @param \Magento\Framework\App\ProductMetadataInterface $productMetadata
     * @param \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $collectionFactory
     * @param \Magento\Catalog\Model\ProductFactory $_productloader
     * @param \Magento\Framework\App\ResourceConnection $resource
     */
    public function __construct(
            EavSetupFactory $eavSetupFactory, 
            \Eniture\FedExSmallPackages\App\State $state,
            \Magento\Framework\App\ProductMetadataInterface $productMetadata,
            \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $collectionFactory,
            \Magento\Catalog\Model\ProductFactory $_productloader,
            \Magento\Framework\App\ResourceConnection $resource,
            \Magento\Eav\Model\Config $eavConfig,
            \Eniture\FedExSmallPackages\Model\EnituremodulesFactory $enModuleFactory
            )
    {
        $this->eavSetupFactory      = $eavSetupFactory;
        $this->productMetadata      = $productMetadata;
        $this->collectionFactory    = $collectionFactory;
        $this->_productloader       = $_productloader;
        $this->_resource            = $resource;
        $this->_connection          = $resource->getConnection(\Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION); 
        
        $this->mageVersion = $this->productMetadata->getVersion();
        
        $this->_eavConfig    = $eavConfig;
        
        $this->_enModuleFactory     = $enModuleFactory->create();
        
        $this->state = $state;
    }
       
    /**
     * {@inheritdoc}
     */
    public function install(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        if (!$this->state->validateAreaCode()) {
            $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_GLOBAL);
        }
                
        $installer = $setup;
        $installer->startSetup();
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $setup]);
        
        $this->getTableNames();
        $this->attrNames();
        $this->renameOldAttributes();
        $this->createOrderDetailAttr($installer);
        
        $this->addFedExSmallAttributes($installer, $eavSetup);
        $this->createFedExSmallWarehouseTable($installer);
        $this->createEnitureModulesTable($installer);
        $this->updateProductDimensionalAttr($installer, $eavSetup);
        $this->checkLTLExistanceColumForEnModules($installer);
        $installer->endSetup();
    }
    
    /**
    * Set Attribute names globally
    */
    function getTableNames() {
        $this->tableNames = array(
            'eav_attribute'                 => $this->_resource->getTableName('eav_attribute'),
            'EnitureModules'                => $this->_resource->getTableName('EnitureModules'),
        );
    }
    
    /**
    * Set Attribute names globally
    */
    function attrNames() {
        $dimAttr = array(
            'length'            => 'length',
            'width'             => 'width',
            'height'            => 'height',
        );
        $dsAttr = array(
            'dropship'          => 'dropship',
            'dropship_location' => 'dropship_location'
        );
        
        $this->attrNames = ($this->mageVersion >= '2.2.5') ? $dsAttr : array_merge($dsAttr, $dimAttr);
    }
    
    /**
    * Rename old attribute name
    */
    function renameOldAttributes() {
        if($this->mageVersion < '2.2.5'){
            $attributes = $this->attrNames;
            foreach ($attributes as $key => $attr) {
                $isExist = $this->_eavConfig->getAttribute('catalog_product', 'wwe_'.$attr.'')->getAttributeId();
                if($isExist != NULL) {
                    $updateSql = "UPDATE ".$this->tableNames['eav_attribute']." SET attribute_code = 'en_".$attr."', is_required = 0 WHERE attribute_code = 'wwe_".$attr."'";
                    $this->_connection->query($updateSql);
                }
            }
        }
    }
    
    /**
     * 
     * @param type $installer
     */
    function createOrderDetailAttr($installer) {
        $installer->getConnection()->addColumn(
            $installer->getTable('sales_order'),
            'order_detail_data',
            [
                'type' => \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                'nullable' => true,
                'default'   => '',
                'comment' => 'Order Detail Widget Data'
            ]
        );
    }
    
    /**
    * add custom product attributes required for product settings
    * @param $installer
    */
    function addFedExSmallAttributes($installer, $eavSetup) {
        $attributes = $this->attrNames;
        if($this->mageVersion < '2.2.5'){
            unset($attributes['dropship'], $attributes['dropship_location']);
            $count = 71;
            foreach ($attributes as $key => $attr) {
                $isExist = $this->_eavConfig->getAttribute('catalog_product', 'en_'.$attr.'')->getAttributeId();
                if($isExist == NULL) {
                    $this->getAttributeArray($eavSetup, 'en_'.$attr, \Magento\Framework\DB\Ddl\Table::TYPE_DECIMAL, ucfirst($attr), 'text', '', $count);
                }
                $count++;
            }
        }
        
        $isendropshipExist = $this->_eavConfig->getAttribute('catalog_product', 'en_dropship')->getAttributeId();

        if($isendropshipExist == NULL){
             $this->getAttributeArray($eavSetup, 'en_dropship', 'int', 'Enable Dropship', 'select', 'Magento\Eav\Model\Entity\Attribute\Source\Boolean', 76);
        }

        $isdropshiplocationExist = $this->_eavConfig->getAttribute('catalog_product', 'en_dropship_location')->getAttributeId();
        if($isdropshiplocationExist == NULL){
            $this->getAttributeArray($eavSetup, 'en_dropship_location', 'int', 'Drop Ship Location', 'select', 'Eniture\FedExSmallPackages\Model\Source\DropshipOptions', 77);
        }else{
            $dataArr = array(
                'source_model' => 'Eniture\FedExSmallPackages\Model\Source\DropshipOptions',
            );
            $this->_connection->update( $this->tableNames['eav_attribute'], $dataArr, "attribute_code = 'en_dropship_location'" );
        }
        
        $isHazmatExist = $this->_eavConfig->getAttribute('catalog_product', 'en_hazmat')->getAttributeId();

        if($isHazmatExist == NULL){
            $this->getAttributeArray($eavSetup, 'en_hazmat', 'int', 'Hazardous Material', 'select', 'Magento\Eav\Model\Entity\Attribute\Source\Boolean', 78);
        }
        $installer->endSetup();
    }
    
    /**
     * 
     * @param type $eavSetup
     * @param type $code
     * @param type $type
     * @param type $label
     * @param type $input
     * @param type $source
     * @param type $order
     * @return type
     */
    function getAttributeArray($eavSetup, $code, $type, $label, $input, $source, $order){
        $attrArr = $eavSetup->addAttribute(
                \Magento\Catalog\Model\Product::ENTITY,
                $code, array(
                    'group'                 => 'Product Details',
                    'type'                  => $type,
                    'backend'               => '',
                    'frontend'              => '',
                    'label'                 => $label,
                    'input'                 => $input,
                    'class'                 => '',
                    'source'                => $source,
                     'global'               => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_STORE,
                    'required'              => false,
                    'visible_on_front'      => false,
                    'is_configurable'       => true,
                    'sort_order'            => $order,
                    'user_defined'          => true
            ));
        
        return $attrArr;
    }
    
    /**
     * create warehouse db table for module warehouse section
     * @param $installer
     */

    function createFedExSmallWarehouseTable($installer) {
        $tableName = $installer->getTable('warehouse');
        if ($installer->getConnection()->isTableExists($tableName) != true) {
           $table = $installer->getConnection()
                ->newTable($tableName)
                ->addColumn('warehouse_id', Table::TYPE_INTEGER, null, array(
                    'identity'  => true,
                    'unsigned'  => true,
                    'nullable'  => false,
                    'primary'   => true,
                    ), 'Id')
                ->addColumn('city', Table::TYPE_TEXT, 200, array(
                    'nullable'  => false,
                    ), 'city')
                ->addColumn('state', Table::TYPE_TEXT, 200, array(
                    'nullable'  => false,
                    ), 'state')
                ->addColumn('zip', Table::TYPE_TEXT, 200, array(
                        'nullable'  => false,
                        ), 'zip')
                ->addColumn('country', Table::TYPE_TEXT, 200, array(
                        'nullable'  => false,
                        ), 'country')
                ->addColumn('location', Table::TYPE_TEXT, 200, array(
                        'nullable'  => false,
                        ), 'location')
                ->addColumn('nickname', Table::TYPE_TEXT, 30, array(
                        'nullable'  => false,
                        ), 'nickname');
            $installer->getConnection()->createTable($table);
        }
        $installer->endSetup();
    }
    
    /**
     * create EnitureModules db table for Ective modules
     * @param $installer
     */
    function createEnitureModulesTable($installer) {

        $moduleTableName = $installer->getTable('enituremodules');
        // Check if the table already exists
        if ($installer->getConnection()->isTableExists($moduleTableName) != true) {

            $table = $installer->getConnection()
                ->newTable($moduleTableName)
                ->addColumn('module_id', Table::TYPE_INTEGER, null, array(
                    'identity'  => true,
                    'unsigned'  => true,
                    'nullable'  => false,
                    'primary'   => true,
                    ), 'id')
                ->addColumn('module_name', Table::TYPE_TEXT, 200, array(
                    'nullable'  => false,
                    ), 'module_name')
                ->addColumn('module_script', Table::TYPE_TEXT, 200, array(
                    'nullable'  => false,
                    ), 'module_script')
                    ->addColumn('dropship_field_name', Table::TYPE_TEXT, 200, array(
                    'nullable'  => false,
                    ), 'dropship_field_name')
                ->addColumn('dropship_source', Table::TYPE_TEXT, 200, array(
                    'nullable'  => false,
                    ), 'dropship_source');
            $installer->getConnection()->createTable($table);
        }
        
        $newModuleName  = 'ENFedExSmpkg';
        $scriptName     = 'Eniture_FedExSmallPackages';
        $isNewModuleExist  = $this->_connection->fetchOne("SELECT count(*) AS count FROM ".$moduleTableName." WHERE module_name = '".$newModuleName."'");

        $this->_enModuleFactory->getCollection()
                                    ->addFilter('module_name', array('eq' => $newModuleName));
        
        
        if($isNewModuleExist == 0){
            $this->_connection->insert($moduleTableName, array('module_name' => $newModuleName, 'module_script' => $scriptName, 'dropship_field_name' => 'en_dropship_location', 'dropship_source' => 'Eniture\FedExSmallPackages\Model\Source\DropshipOptions'));
        }
        
        $installer->endSetup();
    }
    
    /**
     * 
     * @param type $installer
     */
        function updateProductDimensionalAttr($installer, $eavSetup) {
            $lengthChange = $widthChange = $heightChange = false;
            
            if($this->mageVersion > '2.2.4'){
                $productCollection = $this->collectionFactory->create()->addAttributeToSelect( '*' );
                foreach( $productCollection as $_product ) {
                    $product = $this->_productloader->create()->load($_product->getEntityId());
                    
                    $savedEnLength  = $_product->getData( 'en_length' );
                    $savedEnWidth   = $_product->getData( 'en_width' );
                    $savedEnHeight  = $_product->getData( 'en_height' );

                    if(isset($savedEnLength) && $savedEnLength){
                        $product->setData('ts_dimensions_length', $savedEnLength)->getResource()->saveAttribute($product, 'ts_dimensions_length');
                        $lengthChange = true;
                    }

                    if(isset($savedEnWidth) && $savedEnWidth){
                        $product->setData('ts_dimensions_width', $savedEnWidth)->getResource()->saveAttribute($product, 'ts_dimensions_width');
                        $widthChange = true;
                    }

                    if(isset($savedEnHeight) && $savedEnHeight){
                        $product->setData('ts_dimensions_height', $savedEnHeight)->getResource()->saveAttribute($product, 'ts_dimensions_height');
                        $heightChange = true;
                    }
                }
            }
            
            $this->removeEnitureAttr($installer, $lengthChange, $widthChange, $heightChange, $eavSetup);
        }
        
        /**
         * 
         * @param type $installer
         * @param type $lengthChange
         * @param type $widthChange
         * @param type $heightChange
         */
        function removeEnitureAttr($installer, $lengthChange, $widthChange, $heightChange, $eavSetup){
            if($lengthChange == true){
                $eavSetup->removeAttribute(\Magento\Catalog\Model\Product::ENTITY, 'en_length');
            }
            
            if($widthChange == true){
                $eavSetup->removeAttribute(\Magento\Catalog\Model\Product::ENTITY, 'en_width');
            }
            
            if($heightChange == true){
                $eavSetup->removeAttribute(\Magento\Catalog\Model\Product::ENTITY, 'en_height');
            }
        }
        
    /**
     * Add column to eniture modules table
     * @param $installer
     */

        function checkLTLExistanceColumForEnModules($installer){

            $tableName = $installer->getTable('enituremodules');

            if ($installer->getConnection()->isTableExists($tableName) == true) {
                if($installer->getConnection()->tableColumnExists($tableName, 'is_ltl') === false) {

                    $installer->getConnection()->addColumn($tableName,'is_ltl', array(
                        'type'      => Table::TYPE_BOOLEAN,
                        'comment'   => 'module type'
                        ));
                }
            }
            
            $this->_connection->update( $tableName, array('is_ltl' => 0), "module_name = 'ENFedExSmpkg'" );
            $installer->endSetup();
        }
}