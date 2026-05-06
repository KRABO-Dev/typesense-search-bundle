<?php

namespace Krabo\TypesenseSearchBundle\Modules;

use Contao\Input;
use Contao\StringUtil;

class TypesenseSearchModule extends AbstractTypesenseModule
{

    protected $strTemplate = 'mod_typesense_search';

    protected function getType(): string {
      return 'Search';
    }

    public function generate()
    {

        if (\System::getContainer()->get('request_stack')->getCurrentRequest()->get('_scope') == 'backend') {

            $objTemplate = new \BackendTemplate('be_wildcard');
            $objTemplate->id = $this->id;
            $objTemplate->link = $this->name;
            $objTemplate->title = $this->headline;
            $objTemplate->href = 'contao/main.php?do=themes&amp;table=tl_module&amp;act=edit&amp;id=' . $this->id;
            $objTemplate->wildcard = '### ' . strtoupper($GLOBALS['TL_LANG']['FMD']['typesense_search'][0]) . ' ###';

            return $objTemplate->parse();
        }

        return parent::generate();
    }

    protected function compile()
    {
        parent::compile();
        $strKeywords = trim(Input::get('keywords'));
        $this->Template->keywords = StringUtil::specialchars($strKeywords);
    }
}