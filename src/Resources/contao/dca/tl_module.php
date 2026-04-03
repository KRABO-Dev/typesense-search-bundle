<?php

$GLOBALS['TL_DCA']['tl_module']['fields']['typesense_collections'] = [
  'search'                  => true,
  'inputType'               => 'checkboxWizard',
  'eval'                    => array('mandatory'=>true, 'maxlength'=>255, 'tl_class'=>'w50', 'multiple'=>true),
  'sql'                     => "varchar(255) NOT NULL default ''",
  'foreignKey'              => 'tl_typesense_collection.label',
];

$GLOBALS['TL_DCA']['tl_module']['palettes']['typesense_search'] = '{title_legend},name,headline,type;{template_legend:hide},customTpl;{protected_legend:hide:hide},protected;{expert_legend:hide},guests,cssID,space;typesense_collections';