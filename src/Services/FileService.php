<?php
namespace DevsRyan\LaravelEasyAdmin\Services;

use Illuminate\Support\Facades\DB;
use DevsRyan\LaravelEasyAdmin\Services\HelperService;
use Intervention\Image\Facades\Image;
use Hash, Exception;
use Throwable;


class FileService
{

    /**
     * Helper Service.
     *
     * @var class
     */
    protected $helperService;

    /**
     * Template for public model classes
     *
     * @var string
     */
    protected $public_model_template;

    /**
     * Template for app models list
     *
     * @var string
     */
    protected $app_model_list_template;

    /**
     * Template for seeders
     *
     * @var string
     */
    protected $seeder_template;

    /**
     * Image resize
     *
     * @var class
     */
    public $image_sizes = [
        'thumbnail' => '150|auto',
        'small' => '300|auto',
        'medium' => '600|auto',
        'large' => '1200|auto',
        'xtra_large' => '2400|auto',
        'square_thumbnail' => '150|150',
        'square' => '600|600',
        'square_large' => '1200|1200',
        'original' => 'size not altered'
    ];

    /**
     * Image resize
     *
     * @var class
     */
    public $model_types = [
        'page',
        'post',
        'partial'
    ];

    /**
     * Create a new service instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->helperService = new HelperService;

        $path = str_replace('/Services', '', __DIR__).'/FileTemplates/PublicModel.template';
        $this->public_model_template = file_get_contents($path) or die("Unable to open PublicModel.template file");

        $path = str_replace('/Services', '', __DIR__).'/FileTemplates/AppModelList.template';
        $this->app_model_list_template = file_get_contents($path) or die("Unable to open AppModelList.template file");

        $path = str_replace('/Services', '', __DIR__).'/FileTemplates/Seeder.template';
        $this->seeder_template = file_get_contents($path) or die("Unable to open Seeder.template file");
    }

    /**
     * Check if AppModelList is corrupted
     *
     * @return boolean
     */
    public function checkIsModelListCorrupted()
    {
        try {
            $this->helperService->getAllModels();
        }
        catch(Exception $e) {
            return true;
        }
        return false;
    }

    /**
     * Reset AppModelList file
     *
     * @return void
     */
    public function resetAppModelList()
    {
        $write_path = app_path('EasyAdmin/AppModelList.php');
        file_put_contents($write_path, $this->app_model_list_template) or die("Unable to write to file!");
    }

    /**
     * Check if a model has already been added to easy admin
     *
     * @param string $model
     * @return boolean
     */
    public function checkModelExists($model)
    {
        $models = $this->helperService->getAllConvertedModels();
        if (in_array($model, $models)) return true;
        return false;
    }

    /**
     * Check if a public class for this model already exists
     *
     * @param string $model
     * @return boolean
     */
    public function checkPublicModelExists($model_path)
    {
        try {
            $this->helperService->getPublicModel($model_path);
        }
        catch (Exception $e) {
            return false;
        }
        return true;
    }

    /**
     * Add Model into EasyAdmin models list
     *
     * @param string $namespace, $model, $type, $type_target
     * @return void
     */
    public function addModelToList($namespace, $model, $type = 'None', $type_target = null)
    {
        //add model to AppModelList file
        $path = app_path('EasyAdmin/AppModelList.php');

        $package_file = file_get_contents($path) or die("Unable to open file!");

        for($i = 0; $i < strlen($package_file); $i++) {
            //find end of array
            if ($package_file[$i] == ']' && $package_file[$i+1] == ';') {
                $insert = "            '" . rtrim($namespace, '\\') . '.' . $model . "',\n";
                $new_text = substr_replace($package_file, $insert, $i - 8, 0);
                file_put_contents($path, $new_text) or die("Unable to write to file!");
                break;
            }
        }

        // if special type, add to
        if (in_array($type, $this->model_types)) {

            $package_file = file_get_contents($path) or die("Unable to open file!");
            $target = $type . 'Models()';

            $stack = '';
            $target_found = false;
            for($i = 0; $i < strlen($package_file); $i++) {

                if (!$target_found) {
                    if (strlen($stack) + 1 > strlen($target)) $stack = ltrim($stack, $stack[0]) . $package_file[$i];
                    else $stack .= $package_file[$i];
                    if ($stack == $target) $target_found = true;
                }
                else {
                    //find end of array
                    if ($package_file[$i] == ']' && $package_file[$i+1] == ';') {
                        switch($type) {
                            case 'page':
                            case 'post':
                                $insert = "            '" . $model . "',\n";
                                break;
                            case 'partial':
                                if ($type_target === null)
                                    throw new Exception('Invaled type target for model type: ' . $type);
                                    $insert = "            '" . $type_target . '.' . $model . "',\n";
                                break;
                        }

                        $new_text = substr_replace($package_file, $insert, $i - 8, 0);
                        file_put_contents($path, $new_text) or die("Unable to write to file!");
                        break;
                    }
                }

            }
        }
    }

    /**
     * Remove Model from EasyAdmin models list
     *
     * @param string $namespace, $model
     * @return void
     */
    public function removeModelFromList($namespace, $model)
    {
        $path = app_path('EasyAdmin/AppModelList.php');
        $input_lines = file_get_contents($path) or die("Unable to open file!");
        $overwrite_string = preg_replace('/^.*(\.)?'.$model.'\',\n/m', '', $input_lines);
        file_put_contents($path, $overwrite_string) or die("Unable to write to file!");
    }

    /**
     * Add Model into app Models
     *
     * @param string $model_path
     * @return void
     */
    public function addPublicModel($model_path)
    {
        $model = $this->helperService->stripPathFromModel($model_path);
        $write_path = app_path() . '/EasyAdmin/' . $model . '.php';

        //get attributes
        $record = new $model_path;
        $table = $record->getTable();

        $fields = '';
        $columns = DB::select('SHOW COLUMNS FROM ' . $table);
        foreach($columns as $column) {
            $fields .= "'$column->Field',\n            ";
        }

        //comment out fields
        $text = str_replace("{{form_model_fields}}", $this->formFilter($fields), $this->public_model_template);
        $text = str_replace("{{index_model_fields}}", $this->indexFilter($fields), $text);
        $text = str_replace("{{model_name}}", $model, $text);
        $this->createAppDirectory(); //if doesnt exist create public directory
        file_put_contents($write_path, $text) or die("Unable to write to file!");
    }

    /**
     * Remove Model from app Models
     *
     * @param string $model_path
     * @return void
     */
    public function removePublicModel($model_path)
    {
        $model = $this->helperService->stripPathFromModel($model_path);
        $write_path = app_path() . '/EasyAdmin/' . $model . '.php';
        unlink($write_path);
    }

    /////////////////////////////////////
    //FILTER FUNCTIONS FOR ABOVE METHOD//
    /////////////////////////////////////
    private function formFilter($fields)
    {
        $fields = trim($fields);
        $fields = str_replace('\'id', '//\'id', $fields);
        $fields = str_replace('\'remember_token', '//\'remember_token', $fields);
        $fields = str_replace('\'email_verified_at', '//\'email_verified_at', $fields);
        $fields = str_replace('\'created_at', '//\'created_at', $fields);
        $fields = str_replace('\'updated_at', '//\'updated_at', $fields);

        return $fields;
    }
    private function indexFilter($fields)
    {
        $fields = trim($fields);
        $fields = str_replace('\'password', '//\'password', $fields);
        $fields = str_replace('\'remember_token', '//\'remember_token', $fields);
        $fields = str_replace('\'email_verified_at', '//\'email_verified_at', $fields);
        $fields = str_replace('\'created_at', '//\'created_at', $fields);
        $fields = str_replace('\'updated_at', '//\'updated_at', $fields);

        return $fields;
    }


    /**
     * Remove the App/EasyAdmin directory
     *
     * @return void
     */
    public function removeAppDirectory() {
        $dir = app_path() . '/EasyAdmin';

        $it = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it,
                     \RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file) {
            if ($file->isDir()){
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($dir);
    }

    /**
     * Create the App/EasyAdmin directory
     *
     * @return void
     */
    public function createAppDirectory() {
        $dir = app_path() . '/EasyAdmin';
        if (!file_exists($dir)) {
            mkdir($dir);
        }
    }

    /**
     * Store an uploaded file and save the filename in DB
     *
     * @param Request $request
     * @param Model/Null $record
     * @param string $model
     * @param string $field_name
     * @param boolean $general_storage (set true for storage related to General/WYSIWYG)
     * @return void
     */
    public function storeUploadedFile($request, $record, $model, $field_name, $general_storage = false) {
        $file = $request->file($general_storage ? 'img' : $field_name);
        $filename = sha1(time()) . '.' . $file->extension();

        // set file name in DB
        if ($record) {
            $record->$field_name = $filename;
        }

        // check if file is not an image
        $image_info = @getimagesize($file);
        if($image_info == false) {
            $original_path = public_path() . '/devsryan/LaravelEasyAdmin/storage/files/' . $model . '-' .  $field_name;
            $file->move($original_path, $filename);
            return;
        }


        // save original image
        $original_path = public_path() . '/devsryan/LaravelEasyAdmin/storage/img/' . $model . '-' .  $field_name . '/original';
        $file->move($original_path, $filename);

        foreach($this->image_sizes as $name => $size) {
            if ($name == 'original') continue;

            $width = explode("|", $size)[0];
            $height = explode("|", $size)[1];

            $image_resize = Image::make($original_path . '/' . $filename);

            if ($height != 'auto') {
                $image_resize->fit($width, $height);
            }
            else {
                // resize the image to a width of 300 and constrain aspect ratio (auto height)
                $image_resize->resize($width, null, function ($constraint) {
                    $constraint->aspectRatio();
                });
            }

            $path = public_path() . '/devsryan/LaravelEasyAdmin/storage/img/' . $model . '-' .  $field_name . '/' . $name;
            if (!file_exists($path)) {
                mkdir($path, 0777, true);
            }
            $image_resize->save($path . '/' . $filename);
        }

        return [
            'file_name' => $filename,
            'file_path' => rtrim(env('APP_URL'), '/') . '/' . explode("/public/", $original_path)[1] . '/' . $filename
        ];
    }

    /**
     * Unlink multiple files from model/field
     *
     * @param model $model
     * @param string $model_name
     * @param array $file_fields
     * @return void
     */
    public function unlinkFiles($model, $model_name, $file_fields, $target = null) {
        $attributes = $model->attributesToArray();

        foreach($attributes as $field_name => $value) {
            if ($target !== null && $field_name != $target) continue; // Do not erase file when targetting a specific file that needs erased
            if (in_array($field_name, $file_fields)) $this->unlinkFile($model_name, $field_name, $value);
        }
    }

    /**
     * Unlink files from field
     *
     * @param string $model_name
     * @param string $field_name
     * @param string $file_name
     * @return void
     */
    public function unlinkFile($model_name, $field_name, $file_name) {

        // unlink all file paths
        $path = public_path() . '/devsryan/LaravelEasyAdmin/storage/files/' . $model_name . '-' .  $field_name . '/' . $file_name;
        if (file_exists($path)) unlink($path);

        // unlink all image paths
        foreach($this->image_sizes as $name => $size) {
            $path = public_path() . '/devsryan/LaravelEasyAdmin/storage/img/' . $model_name . '-' .  $field_name . '/' . $name . '/' . $file_name;
            if (file_exists($path)) unlink($path);
        }
    }

    /**
     * Generate seeders
     *
     * @return void
     */
    public function generateModelSeeds() {

        $models = $this->helperService->getAllConvertedModels();
        $models[] = 'DevsRyan\LaravelEasyAdmin\Models\EasyAdminImage';

        foreach($models as $model) {

            $required_fields = [];
            $model_name = $this->helperService->stripPathFromModel($model);

            if ($model != 'DevsRyan\LaravelEasyAdmin\Models\EasyAdminImage') { // skip for Easy Admin Image Model
                $required_fields = $this->helperService->getRequiredFields($model);
                $appModel = "App\\EasyAdmin\\" . $model_name;
                $allowed = $appModel::allowed();

                // skip when seed is commented out in allowed array
                if (!in_array('seed', $allowed)) continue;
            }

            $str = "";
            $end = "        ]);\n";
            $results = $model::all();

            foreach($results as $result) {
                if ($str != "") $str .= "        \\$model::create([\n";
                else $str = "\\$model::create([\n";

                foreach($result->getAttributes() as $key => $value) {
                    if ($key === 'password') Hash::make(env('EASY_ADMIN_DEFAULT_PASSWORD', 'secret'));
                    elseif (is_numeric($value)) $str .= "            '$key' => $value,\n";
                    elseif (!in_array($key, $required_fields) && (!$value || $value === '')) $str .= "            '$key' => null,\n";
                    else {
                        $value = str_replace("'", "\'", $value);
                        $value = str_replace(env('APP_URL', null), "' . env('APP_URL') . '", $value);
                        $str .= "            '$key' => '" . $value . "',\n";
                    }
                }
                $str .= $end;
            }

            $file_contents = $this->seeder_template;
            $file_contents = str_replace('{{seed_model_name}}', $model_name . "Seeder", $file_contents);
            $file_contents = str_replace('{{create_fields}}', rtrim($str), $file_contents);

            $write_path = database_path('seeders/' . $model_name . 'Seeder.php');
            file_put_contents($write_path, $file_contents) or die("Unable to write to file!");

            // write to seeder file
            $call_seeder = '$this->call(' . $model_name . 'Seeder::class);';
            $seeder_path = database_path('seeders/DatabaseSeeder.php');
            $seeder_file_contents = file_get_contents($seeder_path) or die("Unable to open seeders/DatabaseSeeder.php file");

            // skip if already included
            if (strpos($seeder_file_contents, $call_seeder) !== false) {
                continue;
            }

            // add to run function
            $i = strpos($seeder_file_contents, 'run');
            while ($i < strlen($seeder_file_contents) && $seeder_file_contents[$i] !== '{') $i++;

            if ($i == strlen($seeder_file_contents)) throw new Exception('Main seeder file has been corrupted');

            $insert = "\n        " . $call_seeder . "\n";
            $seeder_file_contents = substr_replace($seeder_file_contents, $insert, $i + 1, 0);
            file_put_contents($seeder_path, $seeder_file_contents) or die("Unable to write to file!");
        }
    }

    /**
     * Add or update a custom link in the app Models
     *
     * @param string $title
     * @param string $url
     * @return boolean true if created/false if updated
     */
    public function addOrUpdateCustomLink($title, $url) {
        $target = 'customLinks()';

        //add model to AppModelList file
        $path = app_path('EasyAdmin/AppModelList.php');
        $models_file_contents = file_get_contents($path) or die("Unable to open file!");
        $i = strpos($models_file_contents, $target);

        if (array_key_exists($title, $this->helperService->getAllCustomLinks())) {
            while(!(substr($models_file_contents, $i, strlen($title)) === $title)) $i++;

            $quote_count = 0;
            $replace_indexes = [];
            while($quote_count < 3) {
                if ($models_file_contents[$i] === "'") {
                    $quote_count++;
                    if ($quote_count > 1) {
                        $replace_indexes[] = $i;
                    }
                }
                $i++;
            }
            $prev_len = $replace_indexes[1] - $replace_indexes[0];
            $new_text = substr_replace($models_file_contents, $url, $replace_indexes[0] + 1, $prev_len - 1);
            file_put_contents($path, $new_text) or die("Unable to write to file!");
            return false;
        }
        else {
            while(!($models_file_contents[$i] == ']' && $models_file_contents[$i+1] == ';')) $i++;
            $insert = "            '$title' => '$url',\n";
            $new_text = substr_replace($models_file_contents, $insert, $i - 8, 0);
            file_put_contents($path, $new_text) or die("Unable to write to file!");
            return true;
        }

    }

    /**
     * Remove a custom link
     *
     * @param string $title
     * @return void
     */
    public function removeCustomLink($title) {
        $target = 'customLinks()';

        if (array_key_exists($title, $this->helperService->getAllCustomLinks())) {
            //add model to AppModelList file
            $path = app_path('EasyAdmin/AppModelList.php');
            $models_file_contents = file_get_contents($path) or die("Unable to open file!");

            // find section to remove
            $i = strpos($models_file_contents, $target);
            while(!(substr($models_file_contents, $i, strlen($title)) === $title)) $i++;
            $start = $i - 13; $end = $i + strlen($title);
            while($models_file_contents[$end] != "\n") $end++;

            //write new text
            $len = $end - $start + 1;
            $new_text = substr_replace($models_file_contents, '', $start, $len);
            file_put_contents($path, $new_text) or die("Unable to write to file!");
        }
    }

    /**
     * Get width and height of image file in pixels
     *
     * @param file $file
     * @return array
     */
    public function getImageDimensions($file) {
        // get file dimensions
        $data = getimagesize($file);

        return [
            'width' => $data[0],
            'height' => $data[1]
        ];
    }

    /**
     * Get a file size in MB or KB
     *
     * @param file $file
     * @return string
     */
    public function getFileSize($file) {
        //get file size
        $filesize = filesize($file); // bytes
        $filesize_kb = round($filesize / 1024, 1); // kilabytes with 1 digit
        $filesize_mb = round($filesize / 1024 / 1024, 1); // megabytes with 1 digit

        return ($filesize_mb < 1) ? $filesize_kb . 'KB' : $filesize_mb . 'MB';
    }

    /**
     * Check if a file is an image or file
     *
     * @param string $model_name
     * @param string $field_name
     * @param string $value
     * @return void
     */
    public static function checkIsImage($model_name, $field_name, $value) {

        // check if is file
        $path = public_path() . '/devsryan/LaravelEasyAdmin/storage/files/' . $model_name . '-' .  $field_name;
        if (file_exists($path . '/' . $value)) {
            return false;
        }
         return true;
    }

    /**
     * Retrieve a preview link for a model/field name/filename
     *
     * @param string $model_name
     * @param string $field_name
     * @param string $value
     * @param boolean $thumbnail
     * @return void
     */
    public static function getFileLink($model_name, $field_name, $value, $thumbnail = false) {

        // check if is file
        $path = public_path() . '/devsryan/LaravelEasyAdmin/storage/files/' . $model_name . '-' .  $field_name;
        if (file_exists($path . '/' . $value)) {
            return '/devsryan/LaravelEasyAdmin/storage/files/' . $model_name . '-' .  $field_name . '/' . $value;
        }

        // check if is an image
         $path = public_path() . '/devsryan/LaravelEasyAdmin/storage/img/' . $model_name . '-' .  $field_name . '/original';
         if (file_exists($path . '/' . $value)) {
            return '/devsryan/LaravelEasyAdmin/storage/img/'
                . $model_name
                . '-'
                . $field_name
                . ($thumbnail ? '/thumbnail/' : '/original/') . $value;
         }

         return null;
    }
}





































