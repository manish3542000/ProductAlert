<?php
/**
* @author Manish Mehta
* @package Manish_ProductAlert
*/ 
class Manish_ProductAlert_Model_Observer extends Mage_ProductAlert_Model_Observer
{
     /**
     * Process stock emails
     *
     * @param Mage_ProductAlert_Model_Email $email
     * @return Mage_ProductAlert_Model_Observer
     */
    protected function _processStock(Mage_ProductAlert_Model_Email $email)
    {
        $email->setType('stock');
        foreach ($this->_getWebsites() as $website) {
            /* @var $website Mage_Core_Model_Website */
            if (!$website->getDefaultGroup() || !$website->getDefaultGroup()->getDefaultStore()) {
                continue;
            }
            if (!Mage::getStoreConfig(
                self::XML_PATH_STOCK_ALLOW,
                $website->getDefaultGroup()->getDefaultStore()->getId()
            )) {
                continue;
            }
            try {
                $wholeCollection = Mage::getModel('productalert/stock')
                    ->getCollection()
//                    ->addWebsiteFilter($website->getId())
                    ->addFieldToFilter('website_id', $website->getId())
                    ->addFieldToFilter('status', 0)
                ;
//                $wholeCollection->getSelect()->order('alert_stock_id DESC');
                /*       table: !product_alert_stock!
                alert_stock_id: 1
                   customer_id: 1
                    product_id: 1
                    website_id: 1
                      add_date: 2013-04-26 12:08:30
                     send_date: 2013-04-26 12:28:16
                    send_count: 2
                        status: 1
                */
            }
            catch (Exception $e) {
                Mage::log('error-1-collection $e=' . $e->getMessage(), false, 'product_alert_stock_error.log', true);
                $this->_errors[] = $e->getMessage();
                return $this;
            }
            $previousCustomer = null;
            $email->setWebsite($website);
            try {
                $originalCollection = $wholeCollection;
                $count = null;
                $page  = 1;
                $lPage = null;
                $break = false;
                while ($break !== true) {
                    $collection = clone $originalCollection;
                    $collection->setPageSize(1000);
                    $collection->setCurPage($page);
                    $collection->load();
                    if (is_null($count)) {
                        $count = $collection->getSize();
                        $lPage = $collection->getLastPageNumber();
                    }
                    if ($lPage == $page) {
                        $break = true;
                    }
                    Mage::log('page=' . $page, false, 'check_page_count.log', true);
                    Mage::log('collection=' . (string)$collection->getSelect(), false, 'check_page_count.log', true);
                    $page ++;
                    foreach ($collection as $alert) {
                        try {
                            if (!$previousCustomer || $previousCustomer->getId() != $alert->getCustomerId()) {
                                $customer = Mage::getModel('customer/customer')->load($alert->getCustomerId());
                                if ($previousCustomer) {
                                    $email->send();
                                }
                                if (!$customer) {
                                    continue;
                                }
                                $previousCustomer = $customer;
                                $email->clean();
                                $email->setCustomer($customer);
                            }
                            else {
                                $customer = $previousCustomer;
                            }
                            $product = Mage::getModel('catalog/product')
                                ->setStoreId($website->getDefaultStore()->getId())
                                ->load($alert->getProductId());
                            /* @var $product Mage_Catalog_Model_Product */
                            if (!$product) {
                                continue;
                            }
                            $product->setCustomerGroupId($customer->getGroupId());
                            if ($product->isSalable()) {
                                $email->addStockProduct($product);
                                $alert->setSendDate(Mage::getModel('core/date')->gmtDate());
                                $alert->setSendCount($alert->getSendCount() + 1);
                                $alert->setStatus(1);
                                $alert->save();
                            }
                        }
                        catch (Exception $e) {
                            Mage::log('error-2-alert $e=' . $e->getMessage(), false, 'product_alert_stock_error.log', true);
                            $this->_errors[] = $e->getMessage();
                        }
                    }
                }
                Mage::log("\n\n", false, 'check_page_count.log', true);
            } catch (Exception $e) {
                Mage::log('error-3-steps $e=' . $e->getMessage(), false, 'product_alert_stock_error.log', true);
            }
            if ($previousCustomer) {
                try {
                    $email->send();
                }
                catch (Exception $e) {
                    $this->_errors[] = $e->getMessage();
                }
            }
        }
        return $this;
    }
    /**
     * Run process send product alerts
     *
     * @return Inchoo_ProductAlert_Model_Observer
     */
    public function process()
    {
        Mage::log('ProductAlert started @' . now(), false, 'product_alert_workflow.log', true);
        $email = Mage::getModel('productalert/email');
        /* @var $email Mage_ProductAlert_Model_Email */
        $this->_processPrice($email);
        $this->_processStock($email);
        $this->_sendErrorEmail();
        Mage::log('ProductAlert finished @' . now(), false, 'product_alert_workflow.log', true);
        return $this;
    }
}