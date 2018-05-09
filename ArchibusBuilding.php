<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Building;
use App\BuildingUseType;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use \Exception;

class ArchibusBuilding extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'archibus:buildings';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try{
        $log = new Logger('building');
        $path = getcwd() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' .
            DIRECTORY_SEPARATOR . 'archibus_building.log';
        $log->pushHandler(new StreamHandler($path, Logger::WARNING));
        $log->debug("Starting Archibus Building Job");
        $url = "http://archibus.uncc.edu/webtier/BuildingInformationJSON.jsp";

        $data = json_decode(file_get_contents($url), true);

        foreach ($data as $information) {
            $use_type = BuildingUseType::where('name', '=', ucwords(strtolower($information["building-use"])))->first();
            $last_use = BuildingUseType::orderBy('order', 'desc')->first();

            if (empty($use_type)) {
                $use_type = new BuildingUseType ();
                $use_type->name = ucwords(strtolower($information["building-use"]));
                $use_type->order = $last_use->order + 1;
                $use_type->save();
            }

            $building = Building::where('code', '=', $information['building-code'])->first();

            if(empty($building)) {
                $building = new Building ();
                $building->name = $information["building-name"];
                $building->code = $information["building-code"];
                $building->mailing_address = $information["ADDRESS1"];
                $building->latitude = $information["latitude"];
                $building->longitude = $information["longitude"];

                $building->building_use_type = $use_type->id;
                $building->capacity = $information['capacity'];
                $building->save();

            } else {
                $building->name = $information["building-name"];
                $building->mailing_address = $information["ADDRESS1"];
                $building->latitude = $information["latitude"];
                $building->longitude = $information["longitude"];
                $building->capacity = $information['capacity'];

                if($use_type->name != 'Unknown') {
                    $building->building_use_type = $use_type->id;
                }

                $building->save();
            }
        }
        }
        catch (Exception $e) {
            $log->error($e->getMessage());
        }
        finally {
            $log->debug("Ending Archibus Building Job");
        }
    }
}
