<?php

namespace Krabo\TypesenseSearchBundle\Modules;

use Contao\PageModel;

class TypesenseAutocompleteModule extends AbstractTypesenseModule
{

    protected $strTemplate = 'mod_typesense_autocomplete';

    protected function getType(): string {
      return 'Autocomplete';
    }

    public function generate()
    {

        if (\System::getContainer()->get('request_stack')->getCurrentRequest()->get('_scope') == 'backend') {

            $objTemplate = new \BackendTemplate('be_wildcard');
            $objTemplate->id = $this->id;
            $objTemplate->link = $this->name;
            $objTemplate->title = $this->headline;
            $objTemplate->href = 'contao/main.php?do=themes&amp;table=tl_module&amp;act=edit&amp;id=' . $this->id;
            $objTemplate->wildcard = '### ' . strtoupper($GLOBALS['TL_LANG']['FMD']['typesense_autocomplete'][0]) . ' ###';

            return $objTemplate->parse();
        }

        return parent::generate();
    }

    public function compile() {
      parent::compile();

      if (($objTarget = $this->objModel->getRelated('typesense_search_jumpTo')) instanceof PageModel)
      {
        /** @var PageModel $objTarget */
        $this->Template->redirect = $objTarget->getAbsoluteUrl();
      }
    }
}