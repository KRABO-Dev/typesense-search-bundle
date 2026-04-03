<?php
/**
 * Copyright (C) 2026  Jaap Jansma (jaap.jansma@civicoop.org)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Krabo\TypesenseSearchBundle\Controller;

use Contao\Ajax;
use Contao\Backend;
use Contao\BackendTemplate;
use Contao\Environment;
use Contao\Input;
use Contao\System;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;


/**
 * @Route("/typesense/isotopeproduct", defaults={"_scope" = "backend", "_token_check" = true})
 */
class IsotopeProductController extends Backend {

  public function __construct()
  {
    $this->import('BackendUser', 'User');
    parent::__construct();

    $this->User->authenticate();

    \System::loadLanguageFile('default');
    \System::loadLanguageFile('modules');
  }

  /**
   * Handles caching the product XML
   *
   * @return Response
   *
   * @Route("/index", name="typesense_isoproduct_index")
   */
  public function index() {
    $container = System::getContainer();
    $this->Template = new BackendTemplate('be_typesense-iso-product-refresh');
    $this->Template->main = '';

    if (Environment::get('isAjaxRequest'))
    {
      $this->objAjax = new Ajax(Input::post('action'));
      $this->objAjax->executePreActions();
    }

    $this->Template->rt = $container->get('contao.csrf.token_manager')->getToken($container->getParameter('contao.csrf_token_name'))->getValue();

    $this->Template->startMsg = 'Starting';
    $this->Template->endMsg = 'Finished';
    $this->Template->importSubmit = 'Refresh Typesense';

    $this->Template->theme = $this->getTheme();
    $this->Template->base = Environment::get('base');
    $this->Template->language = $GLOBALS['TL_LANGUAGE'];
    $this->Template->pageOffset = $this->Input->cookie('BE_PAGE_OFFSET');
    $this->Template->error = ($this->Input->get('act') == 'error') ? $GLOBALS['TL_LANG']['ERR']['general'] : '';
    $this->Template->skipNavigation = $GLOBALS['TL_LANG']['MSC']['skipNavigation'];
    $this->Template->request = ampersand(Environment::get('request'));
    $this->Template->top = $GLOBALS['TL_LANG']['MSC']['backToTop'];
    $this->Template->expandNode = $GLOBALS['TL_LANG']['MSC']['expandNode'];
    $this->Template->collapseNode = $GLOBALS['TL_LANG']['MSC']['collapseNode'];

    return new Response($this->Template->parse());
  }

}