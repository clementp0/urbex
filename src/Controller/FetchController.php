<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

use App\Entity\Location;
use App\Repository\LocationRepository;
use App\Repository\TypeRepository;
use App\Repository\CountryRepository;

class FetchController extends AbstractController
{
    public function __construct(LocationRepository $locationRepository, TypeRepository $typeRepository, CountryRepository $countryRepository) {
        $this->locationRepository = $locationRepository;

        $this->boardId = $_ENV['BOARD_ID'];
        $this->url = $_ENV['FETCH_BASE_URL'];
        $this->pinBaseUrl = $_ENV['PIN_BASE_URL'];
        $this->imgPath = './img/locations/';

        $this->count = 0;
        $this->maxLoopCount = false; // false = no max 
        $this->newPinCount = 0;

        $this->pinCount = 0;
        $this->newPins = '';
        $this->finished = '';
        $this->error = 'Without Error(s)';

        $this->countries = $countryRepository->findAll();
        $this->typeOptions = [];
        foreach($typeRepository->findAll() as $type) {
            $typeOptions = $type->getTypeOptions();
            foreach($typeOptions as $item) {
                $this->typeOptions[] = $item;
            }
        }
    }
    
    #[Route('/fetch', name: 'app_fetch')]
    public function index(): Response {
        $this->verifyImgFolder();
        $this->getFeed();
    
        return $this->redirect('admin');
    }

    #[Route('/update', name: 'app_update')]
    public function update(): Response {
        $locations = $this->locationRepository->findAll();
        foreach($locations as $location) {
            $this->addCountry($location);
            $this->addType($location);
            $this->locationRepository->add($location);
        }

        $exportDate = './assets/update.json';
        $jsonData = [
            "last_updated" => date("d/m/Y H:i", time()),
        ];
        $jsonString = json_encode($jsonData, JSON_PRETTY_PRINT);
        $fp = fopen($exportDate, 'w');
        fwrite($fp, $jsonString);
        fclose($fp);
    
        return $this->redirect('admin');
    }

    private function verifyImgFolder() {
        if(file_exists($this->imgPath)) return;
        mkdir($this->imgPath, 0777, true);
    }


    private function getResource($option) {
        $data = urlencode(json_encode(['options' => $option]));
    
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $this->url.$data);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);   
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);         
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 2);
        curl_setopt($ch, CURLOPT_NOBODY, false);
        
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            return $this->error(curl_error($ch));
        }
        
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if($http_code == intval(200)) {
            $json = json_decode($response, true);
            $this->parseFeed($json);
        } else {
            $this->error("Ressource introuvable : " . $http_code);
        }
    }

    private function getFeed($bookmarks = false) {
        if($bookmarks === false) {
            $this->getResource(['board_id' => $this->boardId, 'page_size' => '25']);
        } else {
            $this->getResource(['board_id' => $this->boardId, 'page_size' => '25', 'bookmarks' => $bookmarks]);
        }
    }

    private function parseFeed($json) {
        $data = $json['resource_response']['data'];
        foreach($data as $item) {
            if (isset($item['type']) && $item['type'] == 'pin') {
                $this->savePin($item);
            }
        }
        
        $bookmarks = $json['resource']['options']['bookmarks'];
    
        if($this->maxLoopCount !== false && ++$this->count === $this->maxLoopCount) {
            $this->done();
        }elseif ($bookmarks[0] == '-end-') {
            $this->done();
        } else {
            $this->getFeed($bookmarks);
        }
    }


    private function savePin($item) {        
        $exist = $this->locationRepository->findByPid($item['id']) !== null;
        if(!$exist) {
            $location = new Location();

            $imgUrl = $item['images']['orig']['url'];
            $imgName = $item['id'].'.'.pathinfo($imgUrl)['extension'];
            copy($imgUrl, $this->imgPath.$imgName);
            
            preg_match('#(.*".{1}) (.*".{1}) (.*)#', $item['description'], $matches);
            if(isset($matches[1])) $location->setLon((float)$this->convertCoord($matches[1]));
            if(isset($matches[2])) $location->setLat((float)$this->convertCoord($matches[2]));
            if(isset($matches[3])) $location->setName(substr($matches[3], 0, 250));

            $location
                ->setPid((int)$item['id'])
                ->setUrl($this->pinBaseUrl.$item['id'])
                ->setImage($imgName)
                ->setDescription(substr($item['description'], 0, 250))
            ;

            $this->addType($location);
            $this->addCountry($location);

            
            $this->locationRepository->add($location);
            $this->newPinCount++;
        }


        $this->pinCount++;
    }

    private function convertCoord($str) {
        preg_match('#([0-9]+)°([0-9]+)\'([0-9]+.[0-9])"([A-Z])#', $str, $matches);
        if(count($matches) === 5) {
            $pos = in_array($matches[4], ['N', 'E']) ? 1 : -1;
            return $pos*($matches[1]+$matches[2]/60+$matches[3]/3600);
        }
        return $str;
    }

    private function addType($location) {
        $name = $location->getName();
        foreach($this->typeOptions as $typeOption) {
            if(strpos(strtolower($name), $typeOption->getName())) {
                $location->setType($typeOption->getType());
                break;
            }
        }
    }

    private function addCountry($location) {
        $name = $location->getName();
        foreach($this->countries as $country) {
            if(strpos(strtolower($name), $country->getName())) {
                $location->setCountry($country);
                break;
            }
        }
    }

    private function error($error) {
        $this->error = $error;
    }

    private function done() {
        $this->finished = 'Success';
        $this->newPins = $this->newPinCount;

        // Write finished data 
        $exportDate = './assets/export.json';
        $jsonData = [
            "last_fetched" => date("d/m/Y H:i", time()),
            "board" => $this->boardId,
            "finished" => $this->finished,
            "error" => $this->error,
            "total" => $this->pinCount,
            "newpins" => $this->newPins,
            "token" => rand() . "\n"
        ];
        $jsonString = json_encode($jsonData, JSON_PRETTY_PRINT);
        $fp = fopen($exportDate, 'w');
        fwrite($fp, $jsonString);
        fclose($fp);
    }
}