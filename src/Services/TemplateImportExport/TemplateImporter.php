<?php
namespace Exceedone\Exment\Services\TemplateImportExport;

use Illuminate\Support\Facades\File;
use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Model\Authority;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\MailTemplate;
use Maatwebsite\Excel\Facades\Excel;
use ZipArchive;

/**
 * Import Template
 */
class TemplateImporter
{
    /**
     * get template list (get from app folder and vendor/exceedone/exment/templates)
     */
    public static function getTemplates()
    {
        $templates = [];

        foreach (static::getTemplateBasePaths() as $templates_path) {
            $paths = File::glob("$templates_path/*/config.json");
            foreach ($paths as $path) {
                try {
                    $dirname = pathinfo($path)['dirname'];
                    $json = json_decode(File::get($path), true);
                    // add thumbnail
                    if (isset($json['thumbnail'])) {
                        $thumbnail_fullpath = path_join($dirname, $json['thumbnail']);
                        if (File::exists($thumbnail_fullpath)) {
                            $json['thumbnail_fullpath'] = $thumbnail_fullpath;
                        }
                    }
                    array_push($templates, $json);
                } catch (Exception $exception) {
                    //TODO:error handling
                }
            }
        }

        return $templates;
    }

    /**
     * Import template (from display. select item)
     */
    public static function importTemplate($templateName)
    {
        if (!is_array($templateName)) {
            $templateName = [$templateName];
        }
        
        foreach (static::getTemplateBasePaths() as $templates_path) {
            foreach ($templateName as $t) {
                if (!isset($t)) {
                    continue;
                }
                $path = "$templates_path/$t/config.json";
                if (!File::exists($path)) {
                    continue;
                }
                
                static::importFromFile($path);
            }
        }
    }


    /**
     * Import System template (from command)
     */
    public static function importSystemTemplate()
    {
        // get vendor folder
        $templates_base_path = base_path() . '/vendor/exceedone/exment/system_template';
        $path = "$templates_base_path/config.json";

        static::importFromFile($path, true);
    }

    /**
     * Upload template and import (from display)
     */
    public static function uploadTemplate($uploadFile)
    {
        // store uploaded file
        $filename = $uploadFile->store('template_tmp', 'local');
        $fullpath = getFullpath($filename, 'local');
        $tmpfolderpath = path_join(pathinfo($fullpath)['dirname'], pathinfo($fullpath)['filename']);
        // zip
        $zip = new ZipArchive;
        $res = $zip->open($fullpath);
        if ($res !== true) {
            //TODO:error
        }

        //Check existed file config (config.json)
        $config_path = null;
        $thumbnail_path = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $fileInfo = $zip->getNameIndex($i);
            if ($fileInfo === 'config.json') {
                $zip->extractTo($tmpfolderpath);
                $config_path = path_join($tmpfolderpath, array_get($stat, 'name'));
            } elseif (pathinfo($fileInfo)['filename'] === 'thumbnail') {
                $thumbnail_path = path_join($tmpfolderpath, array_get($stat, 'name'));
            }
        }

        //
        if (isset($config_path)) {
            // get config.json
            $json = json_decode(File::get($config_path), true);
            if (!isset($json)) {
                // TODO:エラー
                return;
            }

            // get template name
            $template_name = array_get($json, 'template_name');
            if (!isset($template_name)) {
                //TODO:エラー
                return;
            }

            // copy to app/templates path
            $app_template_path = path_join(static::getTemplatePath(), $template_name);
            
            if (!File::exists($app_template_path)) {
                File::makeDirectory($app_template_path);
            }
            // copy config
            File::copy($config_path, path_join($app_template_path, 'config.json'));
            //
            if (isset($thumbnail_path)) {
                File::copy($thumbnail_path, path_join($app_template_path, pathinfo($thumbnail_path)['basename']));
            }
            
            return $template_name;
        }
    }

    /**
     * upload from excel and import
     */
    public static function uploadTemplateExcel($file){
        // template file settings as json
        $settings = [];

        // loop for excel sheets
        foreach(Define::TEMPLATE_IMPORT_EXCEL_SHEETNAME as $sheetname){
            $reader = Excel::selectSheets($sheetname)->load($file->getRealPath());
            // if null(cannot find sheet), add as empty array.
            if ($reader == null)
            {
                $settings[$sheetname] = [];
                continue;
            }

            // read cell
            $sheet = $reader->getSheet();
            $data = [];
            foreach ($reader->all() as $index => $cells)
            {
                // if first row, it's view name row, so continue
                if($index == 0){continue;}
                // set row no. row no is $index + 1;
                $rowno = $index + 2;
                // if empty row, break
                $cell = $sheet->getCellByColumnAndRow(0,$rowno)->getValue();
                if(!isset($cell)){
                    break;
                }

                $data[] = $cells->all();
            }
        
            // set config json
            $settings[$sheetname] = $data;
        }

        // convert custom_columns to custom_tables->custom_columns
        if(array_key_exists('custom_columns', $settings) && array_key_exists('custom_tables', $settings)){
            $custom_columns = array_get($settings, 'custom_columns');
            foreach($custom_columns as &$custom_column){
                // get table name
                $table_name = array_get($custom_column, 'table_name');
                // find $settings->custom_tables
                if (!isset($table_name)) {
                    continue;
                }
                // get target custom table
                foreach($settings['custom_tables'] as &$custom_table){
                    if($table_name != array_get($custom_table, 'table_name')){
                        continue;
                    }
                    // set custom_column to $custom_table
                    $target_custom_table_columns = array_get($custom_table, 'custom_columns', []);
                    $target_custom_table_columns[] = array_dot_reverse($custom_column);
                    $custom_table['custom_columns'] = $target_custom_table_columns;
                    // remove custom column table name
                    array_forget($custom_column, 'table_name');
                    // jump to next column
                    break;
                }
            }
        }

        // forget custom_columns array
        array_forget($settings, 'custom_columns');

        return $settings;
    }

    /**
     * execute import from file
     */
    protected static function importFromFile($basePath, $system_flg=false)
    {
        // If file not exists
        if (!File::exists($basePath)) {
            // TODO:エラー
        }

        // Get file
        $filestring = File::get($basePath);
        $json = json_decode($filestring, true);
        if (!isset($json)) {
            // TODO:エラー
            return;
        }

        static::import($json, $system_flg);
    }

    /**
     * execute
     */
    public static function import($json, $system_flg = false)
    {
        \DB::transaction(function() use($json, $system_flg){
            // Loop by tables
            foreach (array_get($json, "custom_tables") as $table) {
                // Create tables. --------------------------------------------------
                $table_name = array_get($table, 'table_name');
                $obj_table = CustomTable::firstOrNew(['table_name' => $table_name]);
                $obj_table->table_name = $table_name;
                $obj_table->description = array_get($table, 'description');
                $obj_table->icon = array_get($table, 'icon');
                $obj_table->color = array_get($table, 'color');
                $obj_table->one_record_flg = boolval(array_get($table, 'one_record_flg'));
                $obj_table->search_enabled = boolval(array_get($table, 'search_enabled'));
                $obj_table->system_flg = $system_flg;

                // if contains table view name in config
                if(array_key_value_exists('table_view_name', $table)){
                    $obj_table->table_view_name = array_get($table, 'table_view_name');
                }
                // not exists, get lang using app config
                else{
                    $obj_table->table_view_name = exmtrans("custom_table.system_definitions.$table_name");
                }
                $obj_table->saveOrFail();

                // Create database table.
                $table_name = array_get($table, 'table_name');
                createTable($table_name);
            }

            // Re-Loop by tables and create columns
            foreach (array_get($json, "custom_tables") as $table) {
                // find tables. --------------------------------------------------
                $obj_table = CustomTable::firstOrNew(['table_name' => array_get($table, 'table_name')]);
                
                // Create columns. --------------------------------------------------
                if (array_key_exists('custom_columns', $table)) {
                    $table_columns = [];
                    foreach (array_get($table, 'custom_columns') as $column) {
                        $column_name = array_get($column, 'column_name');
                        $obj_column = CustomColumn::firstOrNew(['custom_table_id' => $obj_table->id, 'column_name' => $column_name]);
                        $obj_column->column_name = $column_name;
                        $obj_column->column_type = array_get($column, 'column_type');
                        $obj_column->system_flg = $system_flg;

                        ///// set options
                        $options = array_get($column, 'options');
                        if (is_null($options)) {
                            $options = [];
                        }
                        // if options has select_target_table_name, get id
                        if (array_key_exists('select_target_table_name', $options)) {
                            $custom_table = CustomTable::findByName(array_get($options, 'select_target_table_name'));
                            $id = $custom_table->id ?? null;
                            // not set id, continue
                            if (!isset($id)) {
                                continue;
                            }
                            $options['select_target_table'] = $id;
                            array_forget($options, 'select_target_table_name');
                        }

                        // if column type is calc, set dynamic val
                        if(array_get($column, 'column_type') == 'calc'){
                            $calc_formula = array_get($options, 'calc_formula');
                            // if $calc_formula is string, convert to json
                            if(is_string($calc_formula)){
                                $calc_formula = json_decode($calc_formula, true);
                            }
                            if(is_array($calc_formula)){
                                foreach($calc_formula as &$c){
                                    // if not dynamic, continue
                                    if(array_get($c, 'type') != 'dynamic'){
                                        continue;
                                    }
                                    // set id
                                    //*caution!! Don't use where 'column_name' because if same name but other table, wrong match.
                                    //$c['val'] = CustomColumn::where('column_name', array_get($c, 'val'))->first()->id ?? null;
                                    $c['val'] = $obj_table->custom_columns()->where('column_name', array_get($c, 'val'))->first()->id ?? null;
                                }
                            }
                            // set as json string
                            $options['calc_formula'] = json_encode($calc_formula);
                        }

                        // remove null value
                        $options = collect($options)->filter(function($option){
                            return isset($option);
                        })->toArray();

                        $obj_column->options = $options;

                        ///// set view name
                        // if contains column view name in config
                        if(array_key_value_exists('column_view_name', $column)){
                            $obj_column->column_view_name = array_get($column, 'column_view_name');
                        }
                        // not exists, get lang using app config
                        else{
                            $obj_column->column_view_name = exmtrans("custom_column.system_definitions.$column_name");
                        }

                        array_push($table_columns, $obj_column);
                    }

                    $obj_table->custom_columns()->saveMany($table_columns);
                }

                // Create database table.
                $table_name = array_get($table, 'table_name');
                
                // alter table
                foreach (getSearchEnabledColumns($table_name) as $column) {
                    alterColumn($table_name, array_get($column, 'column_name'));
                }
            }

            // Loop relations.
            if (array_key_exists('custom_relations', $json)) {
                foreach (array_get($json, "custom_relations") as $relation) {
                    $parent_id = CustomTable::findByName(array_get($relation, 'parent_custom_table_name'))->id ?? null;
                    $child_id = CustomTable::findByName(array_get($relation, 'child_custom_table_name'))->id ?? null;
                    if (!isset($parent_id) || !isset($child_id)) {
                        continue;
                    }
                    
                    // Create relations. --------------------------------------------------
                    $obj_relation = CustomRelation::firstOrNew([
                        'parent_custom_table_id' => $parent_id
                        , 'child_custom_table_id' => $child_id
                        ]);
                    $obj_relation->parent_custom_table_id = $parent_id;
                    $obj_relation->child_custom_table_id = $child_id;
                    $obj_relation->relation_type = array_get($relation, 'relation_type');
                    $obj_relation->saveOrFail();


                }
            }

            // loop for form
            if (array_key_exists('custom_forms', $json)) {
                foreach (array_get($json, "custom_forms") as $form) {
                    $table = CustomTable::findByName(array_get($form, 'table_name'));
                    // Create form --------------------------------------------------
                    $obj_form = CustomForm::firstOrNew([
                        'custom_table_id' => $table->id
                        ]);
                    $obj_form->form_view_name = array_get($form, 'form_view_name');
                    $obj_form->saveOrFail();

                    // Create form block
                    foreach (array_get($form, "custom_form_blocks") as $form_block) {
                        // target block id
                        if (isset($form_block['form_block_target_table_name'])) {
                            $target_table = CustomTable::findByName($form_block['form_block_target_table_name']);
                        } else {
                            $target_table = $table;
                        }

                        // get form_block_type
                        if (isset($form_block['form_block_type'])) {
                            $form_block_type = $form_block['form_block_type'];
                        } else {
                            $self = $target_table->id == $table->id;
                            if ($self) {
                                $form_block_type = Define::CUSTOM_FORM_BLOCK_TYPE_DEFAULT;
                            } else {
                                // get relation
                                $block_relation = CustomRelation
                                                    ::where('parent_custom_table_id', $table->id)
                                                    ->where('child_custom_table_id', $target_table->id)
                                                    ->first();
                                if (isset($block_relation)) {
                                    $form_block_type = $block_relation->relation_type;
                                } else {
                                    $form_block_type = Define::CUSTOM_FORM_BLOCK_TYPE_RELATION_ONE_TO_MANY;
                                }
                            }
                        }

                        $obj_form_block = CustomFormBlock::firstOrNew([
                            'custom_form_id' => $obj_form->id,
                            'form_block_target_table_id' => $target_table->id,
                        ]);
                        $obj_form_block->custom_form_id = $obj_form->id;
                        $obj_form_block->form_block_type = $form_block_type;
                        $obj_form_block->form_block_target_table_id = $target_table->id;
                        if (!$obj_form_block->exists) {
                            $obj_form_block->available = true;
                        }
                        $obj_form_block->saveOrFail();

                        // create form colunms --------------------------------------------------
                        if (array_key_exists('custom_form_columns', $form_block)) {
                            // get column counts
                            $count = count($obj_form_block->custom_form_columns);
                            foreach (array_get($form_block, "custom_form_columns") as $form_column) {
                                //
                                if (array_key_exists('form_column_type', $form_column)) {
                                    $form_column_type = array_get($form_column, "form_column_type");
                                } else {
                                    $form_column_type = Define::CUSTOM_FORM_COLUMN_TYPE_COLUMN;
                                }

                                $form_column_name = array_get($form_column, "form_column_target_name");
                                switch ($form_column_type) {
                                    // for table column
                                    case Define::CUSTOM_FORM_COLUMN_TYPE_COLUMN:
                                        // get column name
                                        $form_column_target = CustomColumn
                                            ::where('column_name', $form_column_name)
                                            ->where('custom_table_id', $target_table->id)
                                            ->first();
                                        $form_column_target_id = isset($form_column_target) ? $form_column_target->id : null;
                                        break;
                                    default:
                                        $form_column_target = collect(Define::CUSTOM_FORM_COLUMN_TYPE_OTHER_TYPE)->first(function ($item) use ($form_column_name) {
                                            return $item['column_name'] == $form_column_name;
                                        });
                                        $form_column_target_id = isset($form_column_target) ? $form_column_target['id'] : null;
                                        break;
                                }

                                // if not set column id, continue
                                if (!isset($form_column_target_id)) {
                                    continue;
                                }

                                $obj_form_column = CustomFormColumn::firstOrNew([
                                    'custom_form_block_id' => $obj_form_block->id,
                                    'form_column_type' => $form_column_type,
                                    'form_column_target_id' => $form_column_target_id,
                                ]);
                                $obj_form_column->custom_form_block_id = $obj_form_block->id;
                                $obj_form_column->form_column_type = $form_column_type;
                                $obj_form_column->form_column_target_id = $form_column_target_id;
                                if (!$obj_form_column->exists) {
                                    $obj_form_column->order = ++$count;
                                }
                                
                                $options = array_get($form_column, 'options');
                                if (is_null($options)) {
                                    $options = null;
                                }
                                // if has changedata_column_name and changedata_target_column_name, set id
                                if(array_key_value_exists('changedata_column_name', $options) && array_key_value_exists('changedata_column_table_name', $options)){
                                    //*caution!! Don't use where 'column_name' because if same name but other table, wrong match.
                                    //$options['changedata_column_id'] = CustomColumn::where('column_name', $options['changedata_column_name'])->first()->id?? null;
                                    // get using changedata_column_table_name
                                    $options['changedata_column_id'] = CustomTable::findByName($options['changedata_column_table_name'])->custom_columns()->where('column_name', $options['changedata_column_name'])->first()->id?? null;
                                    array_forget($options, 'changedata_column_name');
                                }
                                if(array_key_value_exists('changedata_target_column_name', $options)){
                                    //*caution!! Don't use where 'column_name' because if same name but other table, wrong match.
                                    //$options['changedata_target_column_id'] = CustomColumn::where('column_name', $options['changedata_target_column_name'])->first()->id?? null;
                                    $options['changedata_target_column_id'] = $target_table->custom_columns()->where('column_name', $options['changedata_target_column_name'])->first()->id?? null;
                                    array_forget($options, 'changedata_target_column_name');
                                }

                                $obj_form_column->options = $options;
                                
                                $obj_form_column->saveOrFail();
                            }
                        }
                    }
                }
            }
            
            // loop for view
            if (array_key_value_exists('custom_views', $json)) {
                foreach (array_get($json, "custom_views") as $view) {
                    $table = CustomTable::findByName(array_get($view, 'table_name'));
                    $findArray = [
                        'custom_table_id' => $table->id
                    ];
                    // if set suuid in json, set suuid(for dashbrord list)
                    if(array_key_value_exists('suuid', $view)){
                        $findArray['suuid'] =  array_get($view, 'suuid');
                    }else{
                        $findArray['suuid'] =  short_uuid();
                    }
                    // Create view --------------------------------------------------
                    $obj_view = Customview::firstOrNew($findArray);
                    $obj_view->view_type = array_get($view, 'view_type') ?? Define::VIEW_COLUMN_TYPE_SYSTEM;
                    $obj_view->view_view_name = array_get($view, 'view_view_name');
                    $obj_view->saveOrFail();
                    
                    // create view columns --------------------------------------------------
                    if (array_key_exists('custom_view_columns', $view)) {
                        foreach (array_get($view, "custom_view_columns") as $view_column) {
                            if (array_key_exists('view_column_target_type', $view_column)) {
                                $view_column_target_type = array_get($view_column, "view_column_target_type");
                            } else {
                                $view_column_target_type = Define::VIEW_COLUMN_TYPE_COLUMN;
                            }

                            $view_column_name = array_get($view_column, "view_column_target_name");
                            switch ($view_column_target_type) {
                                    // for table column
                                    case Define::VIEW_COLUMN_TYPE_COLUMN:
                                        // get column name
                                        $view_column_target = CustomColumn
                                            ::where('column_name', $view_column_name)
                                            ->where('custom_table_id', $table->id)
                                            ->first()->id ?? null;
                                        break;
                                    // system column
                                    default:
                                        $view_column_target = collect(Define::VIEW_COLUMN_SYSTEM_OPTIONS)->first(function ($item) use ($view_column_name) {
                                            return $item['name'] == $view_column_name;
                                        })['name'] ?? null;
                                        break;
                                }

                            // if not set column id, continue
                            if (!isset($view_column_target)) {
                                continue;
                            }

                            $obj_view_column = CustomviewColumn::firstOrNew([
                                'custom_view_id' => $obj_view->id,
                                'view_column_target' => $view_column_target,
                                'order' => array_get($view_column, "order"),
                            ]);                    
                            $obj_view_column->saveOrFail();
                        }
                    }
                    
                    // create view filters --------------------------------------------------
                    if (array_key_exists('custom_view_filters', $view)) {
                        foreach (array_get($view, "custom_view_filters") as $view_filter) {
                            // get view_filter_target_type for getting view_filter_target_name
                            if (array_key_exists('view_filter_target_type', $view_filter)) {
                                $view_filter_target_type = array_get($view_filter, "view_filter_target_type");
                            } else {
                                $view_filter_target_type = Define::VIEW_COLUMN_TYPE_COLUMN;
                            }

                            $view_filter_name = array_get($view_filter, "view_filter_target_name");
                            switch ($view_filter_target_type) {
                                    // for table column
                                    case Define::VIEW_COLUMN_TYPE_COLUMN:
                                        // get column id
                                        $view_filter_target = CustomColumn
                                            ::where('column_name', $view_filter_name)
                                            ->where('custom_table_id', $target_table->id)
                                            ->first()->id ?? null;
                                        break;
                                    // system column
                                    default:
                                        $view_filter_target = collect(Define::VIEW_COLUMN_SYSTEM_OPTIONS)->first(function ($item) use ($view_filter_name) {
                                            return $item['name'] == $view_filter_name;
                                        })['name'] ?? null;
                                        break;
                                }

                            // if not set filter_target id, continue
                            if (!isset($view_filter_target)) {
                                continue;
                            }

                            $obj_view_filter = CustomviewFilter::firstOrNew([
                                'custom_view_id' => $obj_view->id,
                                'view_filter_target' => $view_filter_target,
                                'view_filter_condition' => array_get($view_filter, "view_filter_condition"),
                                'view_filter_condition_value_text' => array_get($view_filter, "view_filter_condition_value_text"),
                            ]);                    
                            $obj_view_filter->saveOrFail();
                        }
                    }
                }
            }

            // Loop for authorities.
            if (array_key_exists('authorities', $json)) {
                foreach (array_get($json, "authorities") as $authority) {
                    // Create authority. --------------------------------------------------
                    $obj_authority = Authority::firstOrNew(['authority_type' => array_get($authority, 'authority_type'), 'authority_name' => array_get($authority, 'authority_name')]);
                    $obj_authority->authority_type = array_get($authority, 'authority_type');
                    $obj_authority->authority_name = array_get($authority, 'authority_name');
                    $obj_authority->authority_view_name = array_get($authority, 'authority_view_name');
                    $obj_authority->description = array_get($authority, 'description');
                    $obj_authority->default_flg = boolval(array_get($authority, 'default_flg'));

                    // Create authority detail.
                    if (array_key_exists('permissions', $authority)) {
                        $permissions = [];
                        foreach (array_get($authority, "permissions") as $permission) {
                            $permissions[$permission] = "1";
                        }
                        $obj_authority->permissions = $permissions;
                    }
                    $obj_authority->saveOrFail();
                }
            }

            // loop for dashboard
            if (array_key_value_exists('dashboards', $json)) {
                foreach (array_get($json, "dashboards") as $dashboard) {
                    // Create dashboard --------------------------------------------------
                    $obj_dashboard = Dashboard::firstOrNew([
                        'dashboard_name' => array_get($dashboard, "dashboard_name")
                    ]);
                    $obj_dashboard->dashboard_type = array_get($dashboard, 'dashboard_type');
                    $obj_dashboard->dashboard_view_name = array_get($dashboard, 'dashboard_view_name');
                    $obj_dashboard->row1 = array_get($dashboard, 'row1');
                    $obj_dashboard->row2 = array_get($dashboard, 'row2');
                    // if set suuid in json, set suuid(for dashbrord list)
                    if(array_key_value_exists('suuid', $dashboard)){
                        $obj_dashboard->suuid = array_get($dashboard, 'dashboard_suuid');
                    }
                    $obj_dashboard->saveOrFail();
                    
                    // create dashboard boxes --------------------------------------------------
                    if (array_key_exists('dashboard_boxes', $dashboard)) {
                        foreach (array_get($dashboard, "dashboard_boxes") as $dashboard_box) {
                            
                            $obj_dashboard_box = DashboardBox::firstOrNew([
                                'dashboard_id' => $obj_dashboard->id,
                                'row_no' => array_get($dashboard_box, "row_no"),
                                'column_no' => array_get($dashboard_box, "column_no"),
                            ]);
                            $obj_dashboard_box->dashboard_box_view_name = array_get($dashboard_box, "dashboard_box_view_name");
                            $obj_dashboard_box->dashboard_box_type = array_get($dashboard_box, "dashboard_box_type");

                            // set options
                            $options = $obj_dashboard_box->options;
                            // switch dashboard_box_type
                            switch($obj_dashboard_box->dashboard_box_type){
                                // system box
                                case Define::DASHBOARD_BOX_TYPE_SYSTEM:
                                    $options['target_system_id'] = collect(Define::DASHBOARD_BOX_SYSTEM_PAGES)->first(function($value) use($dashboard_box){
                                        return array_get($value, 'name') == array_get($dashboard_box, 'options.target_system_name');
                                    })['id'] ?? null;
                                    break;
                                
                                // list
                                case Define::DASHBOARD_BOX_TYPE_LIST:
                                    // get target table
                                    $options['target_table_id'] = CustomTable::findByName(array_get($dashboard_box, 'options.target_table_name'))->id ?? null;
                                    // get target view using suuid
                                    $options['target_view_id'] = CustomView::findBySuuid(array_get($dashboard_box, 'options.target_view_suuid'))->id ?? null;
                                    break;
                            }

                            $obj_dashboard_box->options = $options;
                            $obj_dashboard_box->saveOrFail();
                        }
                    }
                }
            }

            // loop for menu
            if (array_key_exists('admin_menu', $json)) {
                // order by parent_name is null, not null
                $menulist = collect(array_get($json, "admin_menu"));
                // loop for parent_name is null(root), and next parent name has value
                foreach ([0, 1] as $hasname) {
                    foreach ($menulist as $menu) {
                        // Create menu. --------------------------------------------------
                        // get parent id
                        $parent_id = null;
                        // get parent id from parent_name
                        if (array_key_exists('parent_name', $menu)) {
                            // if $hasname is 0, $menu['parent_name'] is not null(not root) then continue
                            if ($hasname == 0 && !is_null($menu['parent_name'])) {
                                continue;
                            }
                            // if $hasname is 1, $menu['parent_name'] is null(root) then continue
                            elseif ($hasname == 1 && is_null($menu['parent_name'])) {
                                continue;
                            }

                            $parent = Menu::where('menu_name', $menu['parent_name'])->first();
                            if (isset($parent)) {
                                $parent_id = $parent->id;
                            }
                        }
                        if (is_null($parent_id)) {
                            $parent_id = 0;
                        }

                        // set title
                        if(array_key_value_exists('title', $menu)){
                            $title = array_get($menu, 'title');
                        }
                        // title not exists, translate
                        else{
                            $translate_key = array_key_value_exists('menu_target_name', $menu) ? array_get($menu, 'menu_target_name') : array_get($menu, 'menu_name');
                            $title = exmtrans('menu.system_definitions.'.$translate_key);
                        }

                        $obj_menu = Menu::firstOrNew(['menu_name' => array_get($menu, 'menu_name'), 'parent_id' => $parent_id]);
                        $obj_menu->menu_type = array_get($menu, 'menu_type');
                        $obj_menu->menu_name = array_get($menu, 'menu_name');
                        $obj_menu->title = $title;
                        $obj_menu->parent_id = $parent_id;

                        // get menu target id
                        if (isset($menu['menu_target_id'])) {
                            $obj_menu->menu_target = $menu['menu_target_id'];
                        }
                        // get menu target id from menu_target_name
                        elseif (isset($menu['menu_target_name'])) {
                            // case plugin or table
                            switch ($menu['menu_type']) {
                                case Define::MENU_TYPE_PLUGIN:
                                    $parent = Plugin::where('plugin_name', $menu['menu_target_name'])->first();
                                    if (isset($parent)) {
                                        $obj_menu->menu_target = $parent->id;
                                    }
                                    break;
                                case Define::MENU_TYPE_TABLE:
                                    $parent = CustomTable::findByName($menu['menu_target_name']);
                                    if (isset($parent)) {
                                        $obj_menu->menu_target = $parent->id;
                                    }
                                    break;
                            }
                        }

                        // get order
                        if (isset($menu['order'])) {
                            $obj_menu->order = $menu['order'];
                        } else {
                            $obj_menu->order = Menu::where('parent_id', $obj_menu->parent_id)->max('order') + 1;
                        }

                        ///// icon
                        if (isset($menu['icon'])) {
                            $obj_menu->icon = $menu['icon'];
                        }
                        // else, get icon from table, system, etc
                        else{
                            switch($obj_menu->menu_type){
                                case Define::MENU_TYPE_SYSTEM:
                                    $obj_menu->icon = array_get(Define::MENU_SYSTEM_DEFINITION, $obj_menu->menu_name.".icon");
                                    break;
                                case Define::MENU_TYPE_TABLE:
                                    $obj_menu->icon = CustomTable::findByName($obj_menu->menu_name)->icon ?? null;
                                    break;
                            }
                        }

                        ///// uri
                        if (isset($menu['uri'])) {
                            $obj_menu->uri = $menu['uri'];
                        }
                        // else, get icon from table, system, etc
                        else{
                            switch($obj_menu->menu_type){
                                case Define::MENU_TYPE_SYSTEM:
                                    $obj_menu->uri = array_get(Define::MENU_SYSTEM_DEFINITION, $obj_menu->menu_name.".uri");
                                    break;
                                case Define::MENU_TYPE_TABLE:
                                    $obj_menu->uri = $obj_menu->menu_name;
                                    break;
                                case Define::MENU_TYPE_TABLE:
                                    $obj_menu->uri = '#';
                                    break;
                            }
                        }

                        $obj_menu->saveOrFail();
                    }
                }
            }
            
            // Loop for mail templates
            if (array_key_exists('mail_templates', $json)) {
                foreach (array_get($json, "mail_templates") as $mail_template) {
                    // Create mail template --------------------------------------------------
                    $obj_mail_template = MailTemplate::firstOrNew(['mail_name' => array_get($mail_template, 'mail_name')]);
                    $obj_mail_template->mail_name = array_get($mail_template, 'mail_name');
                    $obj_mail_template->mail_view_name = array_get($mail_template, 'mail_view_name');
                    $obj_mail_template->mail_subject = array_get($mail_template, 'mail_subject');

                    // get body
                    $body = array_get($mail_template, 'mail_body');
                    $obj_mail_template->mail_body = preg_replace("/\r\n|\r|\n/", "\n", $body);
                    $obj_mail_template->system_flg = $system_flg;

                    $obj_mail_template->saveOrFail();
                }
            }
            

        });
    }


    /**
     * set MailTemplate info to config
     */
    protected static function setTemplateMailTemplate(&$config){
        // get mail_templates --------------------------------------------------
        $mail_templates = MailTemplate::all()->toArray();
        foreach ($mail_templates as &$mail_template) {
            // remove others
            $mail_template = array_only($mail_template, ['mail_name', 'mail_subject', 'mail_body']);
        }
        $config['mail_templates'] = $mail_templates;
    }

    protected static function getTemplateBasePaths()
    {
        return [static::getTemplatePath(), base_path().'/vendor/exceedone/exment/templates'];
    }

    protected static function getTemplatePath()
    {
        $path = app_path("Templates");
        if (!File::exists($path)) {
            File::makeDirectory($path, 0775, true);
        }
        return $path;
    }
}
