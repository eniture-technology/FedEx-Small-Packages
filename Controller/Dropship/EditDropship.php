<?php
namespace Eniture\FedExSmallPackages\Controller\Dropship;

use \Magento\Framework\App\Action\Action;

class EditDropship extends Action
{
    protected $_dataHelper;

    /**
     * 
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Eniture\FedExSmallPackages\Helper\Data $dataHelper
     * @param \Eniture\FedExSmallPackages\Model\WarehouseFactory $warehouseFactory
     */
    public function __construct(
            \Magento\Framework\App\Action\Context $context,
            \Eniture\FedExSmallPackages\Helper\Data $dataHelper,
            \Eniture\FedExSmallPackages\Model\WarehouseFactory $warehouseFactory
    ) {
        $this->_dataHelper = $dataHelper;
        $this->_warehouseFactory    = $warehouseFactory->create();
        parent::__construct($context);
    }
    
    /**
     * @return string
     */
    function execute()
    {
        foreach ($this->getRequest()->getPostValue() as $key => $post){
            $editDsData[$key] = filter_var( $post, FILTER_SANITIZE_STRING );
        }

        $getDropshipId  = $editDsData['edit_id'];
        $dropshipList   = $this->fetchDropshipList('dropship', $getDropshipId);
        //Change html entities code
        $nick = $dropshipList[0]['nickname'];
        $dropshipList[0]['nickname'] = html_entity_decode($nick,ENT_QUOTES);

        $this->getResponse()->setHeader('Content-type', 'application/json');
        $this->getResponse()->setBody(json_encode($dropshipList)); 
    }
    
    /**
     * 
     * @param type $location
     * @param type $warehouseId
     * @return type
     */
    function fetchDropshipList($location, $warehouseId) {
        $whCollection       = $this->_warehouseFactory->getCollection()
                                    ->addFilter('location', array('eq' => $location))
                                    ->addFilter('warehouse_id', array('eq' => $warehouseId));
        
        return $this->_dataHelper->purifyCollectionData($whCollection);
    }   
}