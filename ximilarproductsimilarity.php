
 
Pull requests
Issues
Marketplace
Explore
 
@barpau 
barpau
/
Ximilar_Presta
Public
Pin
 Unwatch 2 
Fork 0
 Star 0
Code
Issues
Pull requests
Actions
Projects
Wiki
Security
Insights
Settings
 master 
Ximilar_Presta/ximilarproductsimilarity.php  / Jump to 
Go to file

@barpau
barpau Initial commit
Latest commit 9340f1d on Dec 4, 2019
 History
 1 contributor
760 lines (686 sloc)  33.1 KB
Raw Blame
     
<?php
/**
* 2019 Ximilar
*  @author Ximilar, info@ximilar.com
*  @copyright  2019 Ximilar
* @license https://www.ximilar.com
*/

if (!defined('_PS_VERSION_')) {
    exit;
}

class XimilarProductSimilarity extends Module
{
    public function __construct()
    {
        define("MAX_TRIAL_COLLECTION_RECORDS", 1000);

        include_once(_PS_MODULE_DIR_ . 'ximilarproductsimilarity/XimilarConnector.class.php');
        include_once(_PS_MODULE_DIR_ . 'ximilarproductsimilarity/KLogger.class.php');

        $this->name = 'ximilarproductsimilarity';
        $this->ximilar_token = Configuration::get('XIMILAR_PRODUCT_SIMILARITY');
        $this->ximilar_collection_id = Configuration::get('XIMILAR_COLLECTION_ID');
        $this->tab = 'advertising_marketing';
        $this->version = '1.0.0';
        $this->author = 'Ximilar';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array(
            'min' => '1.6',
            'max' => '1.7.6.1'
        );
        $this->bootstrap = true;
        $this->module_key = 'ab6c76b11c763b25e7af25d866a30e1c';

        parent::__construct();

        $this->displayName = $this->l('Visually Related Products');
        $this->description = $this->l('Automatically generates related products as visually similar items.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');

        if (!Configuration::get('XIMILAR_PRODUCT_SIMILARITY')) {
            $this->warning = $this->l('No name provided.');
        }
    }

    public function install()
    {
        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }
        $sql = "CREATE TABLE IF NOT EXISTS `" . _DB_PREFIX_ . "xim_alert_log` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
              `message` varchar(255) NOT NULL,
              `displayed` smallint(6) NOT NULL,
              `date_added` datetime NOT NULL,
              PRIMARY KEY (`id`)
            ) ENGINE =InnoDB DEFAULT CHARSET=utf8";

        $sql_2 = "CREATE TABLE IF NOT EXISTS `" . _DB_PREFIX_ . "xim_record` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `id_image` int(11) NOT NULL,
              `id_product` int(11) NOT NULL,
              `id_category` int(11) NOT NULL,
              `date_add` datetime NOT NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=MyISAM  DEFAULT CHARSET=utf8";

        $result = Db::getInstance()->Execute($sql);
        if (!$result) {
            return false;
        }

        $result = Db::getInstance()->Execute($sql_2);
        if (!$result) {
            return false;
        }

        if (!parent::install() ||
            !Configuration::updateValue('XIMILAR_PRODUCT_SIMILARITY', " ")
        ) {
            return false;
        }

        $this->registerHook('actionProductDelete');
        $this->registerHook('actionProductSave');
        return true;
    }

    public function uninstall()
    {
        $this->xc = new XimilarConnector($this->ximilar_token, $this->ximilar_collection_id);
        $this->xc->logger->logCron("Module to be uninstalled.");
        if (!parent::uninstall() ||
            !Configuration::deleteByName('XIMILAR_PRODUCT_SIMILARITY') ||
            !Configuration::deleteByName('XIMILAR_TRIAL_CATEGORY') ||
            !Configuration::deleteByName('XIMILAR_COLLECTION_ID')
        ) {
            return false;
        }

        $this->xc->flushCollection();

        $result = Db::getInstance()->Execute("DROP TABLE " . _DB_PREFIX_ . "xim_alert_log");
        if (!$result) {
            return false;
        }

        $result = Db::getInstance()->Execute("DROP TABLE " . _DB_PREFIX_ . "xim_record");
        if (!$result) {
            return false;
        }
        $this->xc->logger->logCron("Module uninstalled or reseted.");

        return true;
    }

    public function renderTokenForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs',
                ),
                'description' => $this->l('To get Authorization Key, we invite you first to create an account at') .
                    $this->generateLink(
                        "https://app.ximilar.com/login",
                        "_blank",
                        'https://app.ximilar.com/login'
                    )
                    . $this->newLine() . $this->l('Your Authorization Key will be then available at ')
                    . $this->generateLink(
                        "https://app.ximilar.com/options/",
                        "_blank",
                        "https://app.ximilar.com/options/"
                    ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('Enter Your Authorization Key'),
                        'name' => 'XIMILAR_PRODUCT_SIMILARITY',
                        'value' => '',
                        'size' => 50,
                        'required' => true
                    )
                ),
                'submit' => array(
                    'name' => 'submitAuthorizeToken',
                    'title' => $this->l('Save')
                ),
            ),
        );

        $defaultLang = (int)Configuration::get('PS_LANG_DEFAULT');
        $helper = new HelperForm();

        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;

        // Language
        $helper->default_form_language = $defaultLang;
        $helper->allow_employee_form_lang = $defaultLang;

        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->show_toolbar = false;        // false -> remove toolbar
        $helper->submit_action = 'submitAuthorizeToken';

        // Load current value
        $helper->fields_value['XIMILAR_PRODUCT_SIMILARITY'] = Configuration::get('XIMILAR_PRODUCT_SIMILARITY');

        return $helper->generateForm(array($fields_form));
    }

    public function renderDescriptionForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('About '),
                ),
                'description' => $this->l('Module Visually Related Products helps you link visually similar products. 
                Your customers can find more related products easier. First the system analyses your product photos at 
                Ximilar cloud. That step usually takes a few minutes to process. Once synchronised, you can fill your 
                related items for each product based on visual similarity — with just one click. The related products 
                are only matched within its category, for example shoes.') . $this->newLine() .
                    $this->l('Any products that will be added to your store later will be automatically synchronized 
                    to Ximilar cloud and their related products will be linked automatically. So you don’t have to do
                     anything here.') . $this->newLine()
                    . $this->l('For recently added product to appear in related items of
                      all your existing products, click [ Add new products to related ] which will update the related 
                      products in your shop. This will ensure that related items will also contain products added to 
                      your store later.
                      '),
                ),
            );

        $defaultLang = (int)Configuration::get('PS_LANG_DEFAULT');

        $helper = new HelperForm();
        $helper->show_toolbar = false;

        $helper->default_form_language = $defaultLang;
        $helper->allow_employee_form_lang = $defaultLang;

        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' .
            $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        return $helper->generateForm(array($fields_form));
    }

    public function renderTrialIndexForm()
    {
        $file = _PS_MODULE_DIR_ . 'ximilarproductsimilarity/status.txt';
        if (Tools::file_get_contents($file) == "Indexing finished.") {
            $fields_form = array(
                'form' => array(
                    'legend' => array(
                        'title' => $this->l('SYNCHRONIZE YOUR CATALOG TO XIMILAR CLOUD'),
                    ),

                    'description' => $this->l('For your trial version, we recommend to select a representative category with at 
                    least 100 products. Within the free trial, the plugin displays visually similar items for up to 
                    1000 products from your selected category.'),
                    'input' => array(
                        array(
                            'type' => 'categories',
                            'label' => $this->l('Product Category'),
                            'desc' => $this->l('Product Category.'),
                            'name' => 'category_tree',
                            'use_checkbox' => true,
                            'tree' => array(
                                'id' => 'category_id',
                                'selected_categories' => array(Configuration::get('XIMILAR_TRIAL_CATEGORY')),
                            )
                        )
                    ),
                    'submit' => array(
                        'name' => 'submitCreateCollection',
                        'title' => $this->l('Synchronize Now'),
                        'icon' => 'none',
                        'class' => 'synchronize_btn'
                    ),
                ),
            );
        } else {
            $fields_form = array(
                'form' => array(
                    'legend' => array(
                        'title' => $this->l('SYNCHRONIZE YOUR CATALOG TO XIMILAR CLOUD'),
                    ),

                    'description' => $this->l('Synchronization in process ... '),
                    'input' => array(
                        array(
                            'type' => 'categories',
                            'label' => $this->l('Product Category'),
                            'desc' => $this->l('Product Category.'),
                            'name' => 'category_tree',
                            'use_checkbox' => true,
                            'tree' => array(
                                'id' => 'category_id',
                                'selected_categories' => array(Configuration::get('XIMILAR_TRIAL_CATEGORY')),
                            )
                        )
                    )
                )
            );
        }

        $defaultLang = (int)Configuration::get('PS_LANG_DEFAULT');

        $helper = new HelperForm();
        $helper->show_toolbar = false;

        $helper->default_form_language = $defaultLang;
        $helper->allow_employee_form_lang = $defaultLang;

        $helper->submit_action = 'submitCreateCollection';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' .
            $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        return $helper->generateForm(array($fields_form));
    }

    public function renderIndexForm()
    {
        $file = _PS_MODULE_DIR_ . 'ximilarproductsimilarity/status.txt';
        if (Tools::file_get_contents($file) == "Indexing finished.") {
            $fields_form = array(
                'form' => array(
                    'legend' => array(
                        'title' => $this->l('SYNCHRONIZE YOUR CATALOG TO XIMILAR CLOUD'),
                    ),
                    'description' => $this->l('Start by synchronizing your catalog to Ximilar Cloud.'),
                    'submit' => array(
                        'name' => 'submitCreateCollection',
                        'title' => $this->l('Synchronize Now'),
                        'icon' => 'none',
                        'class' => 'synchronize_btn'
                    ),
                ),
            );
        } else {
            $fields_form = array(
                'form' => array(
                    'legend' => array(
                        'title' => $this->l('SYNCHRONIZE YOUR CATALOG TO XIMILAR CLOUD'),
                    ),
                    'description' => $this->l('Synchronization in process ...'),
                ),
            );
        }

        $defaultLang = (int)Configuration::get('PS_LANG_DEFAULT');

        $helper = new HelperForm();
        $helper->show_toolbar = false;

        $helper->default_form_language = $defaultLang;
        $helper->allow_employee_form_lang = $defaultLang;

        $helper->submit_action = 'submitCreateCollection';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' .
            $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        return $helper->generateForm(array($fields_form));
    }

    public function renderRelatedItemsForm()
    {
        $records_collection = $this->xc->getRecordsCollection();
        $indexed_records_in_collection = count($records_collection);

        if ($indexed_records_in_collection < 100) {
            $fields_form = array(
                'form' => array(
                    'legend' => array(
                        'title' => $this->l('Link related items'),
                    ),
                    'description' => $this->l('You have only ') . $indexed_records_in_collection .
                        $this->l(' records in your collection. Please, index at least 100 records.'),
                ),
            );
        } else {
            $fields_form = array(
                'form' => array(
                    'legend' => array(
                        'title' => $this->l('Link related items'),
                    ),
                    'description' => $this->l('Continue with linking your related items.'),
                    'submit' => array(
                        'name' => 'submitFillRelatedItems',
                        'title' => $this->l('Fill')
                    ),
                ),
            );
        }
        $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ?
            Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->submit_action = 'submitFillRelatedItems';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' .
            $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        return $helper->generateForm(array($fields_form));
    }

    public function renderConfigForm()
    {
        $full_store_url = _PS_BASE_URL_ . __PS_BASE_URI__;
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Configuration'),
                    'icon' => 'icon-cogs',
                ),
                'description' => $this->l('Logger || for development use ') . $this->generateLink($full_store_url .
                    '/modules/ximilarproductsimilarity/logs', "_blank", "click here."),

                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('Ximilar Authorization Key'),
                        'name' => 'XIMILAR_PRODUCT_SIMILARITY',
                        'readonly' => "readonly",
                        'size' => 50

                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Ximilar Collection ID'),
                        'name' => 'XIMILAR_COLLECTION_ID',
                        'readonly' => "readonly",
                        'size' => 50
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Ximilar User Plan'),
                        'name' => 'XIMILAR_USER_PLAN',
                        'readonly' => "readonly",
                        'size' => 50
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Number of photos in Ximilar Collection'),
                        'name' => 'XIMILAR_INDEXED_RECORDS_IN_COLLECTION',
                        'readonly' => "readonly",
                        'size' => 50
                    )
                ),
                'submit' => array(
                    'name' => 'd',
                    'title' => $this->l('Save')
                ),
            ),
        );

        $user_info = $this->xc->getUserDetails();
        $records_collection = $this->xc->getRecordsCollection();
        $indexed_records_in_collection = count($records_collection);

        $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper = new HelperForm();
        $helper->fields_value['XIMILAR_PRODUCT_SIMILARITY'] = Configuration::get('XIMILAR_PRODUCT_SIMILARITY');
        $helper->fields_value['XIMILAR_COLLECTION_ID'] = Configuration::get('XIMILAR_COLLECTION_ID');
        $helper->fields_value['XIMILAR_USER_PLAN'] = $user_info->pricing_plan->name . " | " .
            $user_info->pricing_plan->description;
        if ($user_info->pricing_plan->name == "Free") {
            $helper->fields_value['XIMILAR_INDEXED_RECORDS_IN_COLLECTION'] = $indexed_records_in_collection . " / " .
                MAX_TRIAL_COLLECTION_RECORDS;
        } else {
            $helper->fields_value['XIMILAR_INDEXED_RECORDS_IN_COLLECTION'] = $indexed_records_in_collection;
        }
        $helper->show_toolbar = false;
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ?
            Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->submit_action = 'submitSaveConfigForm';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure='
            . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        return $helper->generateForm(array($fields_form));
    }

    public function getContent()
    {
        $output = null;
        $sql = 'SELECT * FROM ' . _DB_PREFIX_ . 'xim_alert_log WHERE displayed=0';
        foreach (Db::getInstance()->ExecuteS($sql) as $message) {
            $output .= $this->displayError($this->l($message['message']));
            Db::getInstance()->update(
                'xim_alert_log',
                array(
                'displayed' => 1),
                'id =' . (int)$message['id']
            );
        }
        if ($this->ximilar_token != "" && $this->ximilar_token != " ") {
            $this->xc = new XimilarConnector($this->ximilar_token, $this->ximilar_collection_id);
            $user_info = $this->xc->getUserDetails();
            if ($user_info->verified == 0) {
                $output .= $this->displayError(
                    $this->l('Please verify your email first. You can adjust your settings in ')
                    . $this->generateLink(
                        "https://app.ximilar.com/login",
                        "_blank",
                        "https://app.ximilar.com/login"
                    )
                );
                $this->xc->logger->logCron('Error: user\'s email is not verified');
                return $output;
            }
        }

        $this->context->controller->addCSS($this->_path . 'views/css/style.css');

        $this->context->smarty->assign([
            'full_store_url' =>  _PS_BASE_URL_ . __PS_BASE_URI__,
        ]);
        $output .=  $this->display(__FILE__, 'views/templates/admin/ximilarproductsimilarity.tpl');

        if (Configuration::get('XIMILAR_PRODUCT_SIMILARITY') == " " ||
            Configuration::get('XIMILAR_PRODUCT_SIMILARITY') == "") {
            if (Tools::isSubmit('submitAuthorizeToken')) {
                $new_token = trim(pSQL((Tools::getValue('XIMILAR_PRODUCT_SIMILARITY'))));
                $this->xc = new XimilarConnector($new_token, $this->ximilar_collection_id);
                if ($this->xc->checkToken()) {
                    Configuration::updateValue('XIMILAR_PRODUCT_SIMILARITY', $new_token);
                    $this->xc->logger->logCron("Token validated and saved to database.");
                    $this->xc->getResource();

                    $user_info = $this->xc->getUserDetails();
                    $this->xc->logger->logCron("User info: " . print_r($user_info, true));
                    $collection_id = $this->xc->manageCollection();
                    Configuration::updateValue('XIMILAR_COLLECTION_ID', $collection_id);
                    $this->xc->logger->logCron("Module operates with collection ID:  " . $collection_id);
                    $output .= $this->displayConfirmation($this->l('Authorization successful.'));
                    if ($user_info->pricing_plan->name == "Free") {
                        return $output . $this->renderDescriptionForm() . $this->renderTrialIndexForm()
                            . $this->renderRelatedItemsForm() . $this->renderConfigForm();
                    } else {
                        return $output . $this->renderDescriptionForm() . $this->renderIndexForm()
                            . $this->renderRelatedItemsForm() . $this->renderConfigForm();
                    }
                } else {
                    $this->saveAlert($this->l('Authorization could not be completed. Please, check your authorization key.'));
                    $output .= $this->displayError($this->l('Authorization could not be completed. Please, check your 
                    authorization key.'));
                }
            }
            return $output . $this->renderTokenForm();
        } else {
            if (Tools::isSubmit('submitCreateCollection')) {
                //this action moved to ajax_listener.php
            } elseif (Tools::isSubmit('submitFillRelatedItems')) {
                $file = _PS_MODULE_DIR_ . 'ximilarproductsimilarity/status.txt';
                if (Tools::file_get_contents($file) == "Indexing finished.") {
                    $this->xc->logger->logCron("Process link related started.");
                    $this->fillRelatedItems();
                    $output .= $this->displayConfirmation($this->l('Related items linked.'));
                } else {
                    $output .= $this->displayError($this->l('Synchronization process has not finished. Please wait.'));
                }
            }

            $this->xc = new XimilarConnector($this->ximilar_token, $this->ximilar_collection_id);
            $ud = $this->xc->getUserDetails();
            if ($ud->pricing_plan->name == "Free") {
                return $output . $this->renderDescriptionForm() . $this->renderTrialIndexForm()
                    . $this->renderRelatedItemsForm() . $this->renderConfigForm();
            } else {
                return $output . $this->renderDescriptionForm() . $this->renderIndexForm()
                    . $this->renderRelatedItemsForm() . $this->renderConfigForm();
            }
        }
    }

    public function newLine()
    {
        return nl2br("\n");
    }

    public function generateLink($source, $target, $text)
    {
        $this->context->smarty->assign([
            'source' =>  $source,
            '$target' => $target,
            'text' => $text
        ]);

        return $this->display(__FILE__, 'views/templates/admin/links.tpl');
    }

    // ||||||||||||||||||| XIMILAR FUNCTIONS |||||||||||||

    public function indexProducts($id_trial_category, $force_index_id_product = false)
    {
        if ($id_trial_category > 0) {
            $this->xc->logger->logCron("Module works under trial version.");
        }
        $images_in_store = array();
        $id_lang = (int)Context::getContext()->language->id;
        $productObj = new Product();
        $products = @$productObj->getProducts($id_lang, 0, 0, 'id_product', 'DESC');
        foreach ($products as $product) {
            if ($force_index_id_product && $product['id_product'] != $force_index_id_product) {
                continue;
            }
            $id_category = $product['id_category_default'];
            if ($id_trial_category == $id_category || $id_trial_category == 0) {
                $images = Image::getImages($id_lang, $product['id_product']);
                foreach ($images as $image) {
                    $images_in_store[$image['id_image']] = true;
                }
            }
        }
        if (count($images_in_store) < 1) {
            $this->saveAlert($this->l('No images to be inserted.'));
            $this->xc->logger->logCron("No images to be inserted.");
            return 'no_images_to_index';
        }
        $records_collection = $this->xc->getRecordsCollection();
        $this->xc->logger->logCron(count($records_collection) . " record(s) in collection.");
        $missing_records = $this->xc->getMissingRecordsInCollection($images_in_store, $records_collection);

        $allowed_number_of_records =
            $id_trial_category == 0 ? 999999 : MAX_TRIAL_COLLECTION_RECORDS - count($records_collection);
        $this->xc->logger->logCron("Allowed number of new records: " . $allowed_number_of_records);
        if ($allowed_number_of_records == 0) {
            $this->saveAlert($this->l('Number of records reached allowed maximum.'));
            $this->xc->logger->logCron("Number of records reached allowed maximum.");
            return 'max_records_reached';
        }
        foreach ($products as $product) {
            $file = _PS_MODULE_DIR_ . 'ximilarproductsimilarity/status.txt';
            if (Tools::file_get_contents($file) == "Indexing finished.") {
                $this->xc->logger->logCron("Synchronization process cancelled.");
                exit;
            }
            $id_category = $product['id_category_default'];
            if ($id_trial_category == $id_category || $id_trial_category == 0) {
                $id_product = $product['id_product'];
                $cat = new Category($product['id_category_default'], $this->context->language->id);
                $category_name = $cat->name;
                $images = Image::getImages($id_lang, $id_product);
                $product = new Product($id_product, false, Context::getContext()->language->id);
                $records_array = array();
                foreach ($images as $image) {
                    $image_obj = new Image($image['id_image']);
                    $id_image = $image['id_image'];
                    // to avoid inserting duplicate images
                    if (!isset($missing_records[$id_image])) {
                        continue;
                    }
                    $imagePath = _PS_BASE_URL_ . _THEME_PROD_DIR_ . $image_obj->getExistingImgPath() . ".jpg";
                    $this->xc->logger->logCron(
                        "Image inserting... collection id: " . $this->ximilar_collection_id . " | idp: " . $id_product .
                        " | idc:" . $id_category . " | " . $category_name . " | idi:" . $id_image . " | " . $imagePath
                    );
                    $now = date_create('now')->format('Y-m-d H:i:s');
                    Db::getInstance()->insert('xim_record', array(
                        'id_image' => (int)$id_image,
                        'id_product' => (int)$id_product,
                        'id_category' => (int)$id_category,
                        'date_add' => pSQL($now),
                    ));
                    $obj = new stdClass();
                    $obj->_id = $id_image;
                    $obj->_url = $imagePath;
                    $obj->product_id = $id_product;
                    $obj->category = $id_category;
                    $records_array[] = $obj;
                    if ($allowed_number_of_records < 1) {
                        $this->saveAlert($this->l('Number of records reached allowed maximum.'));
                        $this->xc->logger->logCron("Number of records reached allowed maximum.");
                        return 'max_records_reached';
                    }
                    $allowed_number_of_records--;
                }
                if (count($records_array) > 0) {
                    $data = array(
                        "records" =>
                            $records_array
                    );
                    $headers_addons = array("collection-id" => $this->ximilar_collection_id);
                    $this->xc->insertImageToCollection($this->ximilar_token, $data, $headers_addons);
                }
            }
        }
        $collection_details = $this->xc->checkCollection($this->ximilar_collection_id);
        $this->xc->logger->logCron("Collection info: \n" . print_r($collection_details, true));

        $this->xc->logger->logCron(count($missing_records) . " record(s) inserted.");
        $redundant_records = $this->xc->getRedundantRecordsInCollection($images_in_store, $records_collection);
        if (count($redundant_records) > 0) {
            $response = $this->xc->deleteRecords($redundant_records);
            $this->xc->logger->logCron(
                count($redundant_records) . "/" . count($response->answer_records) . " record(s) removed. "
            );
        } else {
            $this->xc->logger->logCron("0 records removed.");
        }
        return 'index_complete';
    }


    public function fillRelatedItems()
    {
        $t = $this->xc->logger->gt();
        $id_lang = (int)Context::getContext()->language->id;
        $productObj = new Product();
        $products = $productObj->getProducts($id_lang, 0, 0, 'id_product', 'DESC');

        foreach ($products as $product) {
            $id_product = $product['id_product'];
            $images = Image::getImages($id_lang, $id_product);
            $image = current($images);

            $arg = array(
                "id" => $image['id_image'],
                "filter" => array("category" => $product['id_category_default'])
            );
            $result = $this->xc->findSimilarAlternatives($arg);
            $related = array();

            foreach ($result as $item) {
                $related[] = $item->product_id;
            }

            unset($related[0]);
            Db::getInstance()->delete('accessory', 'id_product_1 =' . (int)$id_product);
            foreach ($related as $item) {
                Db::getInstance()->insert('accessory', array(
                    'id_product_1' => (int)$id_product,
                    'id_product_2' => (int)($item),
                ));
            }
        }
        $this->xc->logger->logCron(count($products) . " products has been filled in "
            . $this->xc->logger->grt($t) . " seconds.");
    }

    public function saveAlert($message, $to_display = true)
    {
        if ($to_display) {
            $to_display = 0;
        } else {
            $to_display = 1;
        }
        Db::getInstance()->insert('xim_alert_log', array(
            'message' => pSQL($message),
            'displayed' => (int)$to_display,
            'date_added' => 'NOW()'
        ));
    }

    public function hookActionProductDelete($product)
    {
        $this->xc = new XimilarConnector($this->ximilar_token, $this->ximilar_collection_id);
        $sql = 'SELECT * FROM ' . _DB_PREFIX_ . 'xim_record WHERE id_product =' . (int)$product['id_product'];
        $images_in_store = array();
        foreach (Db::getInstance()->ExecuteS($sql) as $record) {
            $images_in_store[] = (int)$record['id_image'];
        }
        $server_output_json = $this->xc->deleteRecords($images_in_store);
        if ($server_output_json->status->code != "220") {
            $this->saveAlert('Record(s) could not be deleted.');
        }
        $this->xc->logger->logCron("Records removed from collection: " . implode(",", $images_in_store));
        Db::getInstance()->delete('xim_record', 'id_product =' . (int)$product['id_product']);
    }

    public function hookActionProductSave($product)
    {
        $this->xc = new XimilarConnector($this->ximilar_token, $this->ximilar_collection_id);
        $result = Db::getInstance()->ExecuteS(
            'SELECT * FROM ' . _DB_PREFIX_ . 'xim_record WHERE id_product =' . (int)$product['id_product']
        );
        if (isset($result[0]['id_product'])) {
            $this->xc->logger->logCron("Product already inserted into collection.");
            return true;
        }
        $this->xc->logger->logCron("hookActionProductSave | Inserting into collection ... ");
        $file = _PS_MODULE_DIR_ . 'ximilarproductsimilarity/status.txt';
        file_put_contents($file, "Indexing in process...");
        $this->indexProducts(0, $product['id_product']);
        file_put_contents($file, "Indexing finished.");
    }
}
© 2022 GitHub, Inc.
Terms
Privacy
Security
Status
Docs
Contact GitHub
Pricing
API
Training
Blog
About
Loading complete
