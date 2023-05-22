<?php

namespace App\Service;

use App\Entity\Location;
use App\Service\LocationService;
use App\Repository\LocationRepository;
use App\Service\DataService;

class PinterestService {
    public function __construct(
        private string $publicDirectory,
        private string $dataDirectory,
        private LocationRepository $locationRepository,
        private LocationService $locationService,
        private DataService $dataService,
    ) {
        $this->boardId = $_ENV['PINTEREST_BOARD_ID'];
        $this->url = $_ENV['PINTEREST_FETCH_BASE_URL'];
        $this->pinBaseUrl = $_ENV['PINTEREST_PIN_BASE_URL'];
        $this->pinUrl = $_ENV['PINTEREST_PIN_COUNT_URL'];
        $this->imgPath = $_ENV['IMG_LOCATION_PATH'];
        $this->source = 'Pinterest';

        $this->count = 0;
        $this->maxLoopCount = false; // false = no max 
        $this->newPinCount = 0;

        $this->pinCount = 0;
        $this->error = 'Without Error(s)';
        $this->pinTotal = 0;
    }
    
    public function getPinTotal($outside = false) {
        try {
            $response = $this->dataService->fetchUrl($this->pinUrl); 
        } catch(\Exception $e) {
            if($outside) return $e->getMessage();
            else $this->error($e->getMessage());
        }
        
        if (preg_match('/"pin_count":(\d+)/', $response, $matches)) return intval($matches[1]);
    }
    
    public function fetch() {
        $this->pinTotal = $this->getPinTotal();
        if(!$this->pinTotal || (int)$this->pinTotal === 0) return
        $this->dataService->initFile($this->dataDirectory.'pin.txt');
        $this->dataService->verifyFolder($this->publicDirectory.$this->imgPath, true);
        $this->getFeed();
    }

    public function getFeed($bookmarks = false) {
        if($bookmarks === false) {
            $this->getResource(['board_id' => $this->boardId, 'page_size' => '25']);
        } else {
            $this->getResource(['board_id' => $this->boardId, 'page_size' => '25', 'bookmarks' => $bookmarks]);
        }
    }

    private function getResource($option) {
        $data = urlencode(json_encode(['options' => $option]));
        try {
            $response = $this->dataService->fetchurl($this->url.$data);
        } catch(\Exception $e) {
            return $this->error($e->getMessage());
        }
        
        $json = json_decode($response, true);
        $this->parseFeed($json);
    }

    private function parseFeed($json) {
        $data = $json['resource_response']['data'];;
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
        $exist = $this->locationRepository->findOneBy(['pid' => $item['id'], 'source' => $this->source]) !== null;
        if(!$exist) {
            $location = new Location();

            $imgUrl = $item['images']['orig']['url'];
            $imgName = $this->locationService->generateImgUid().'.'.pathinfo($imgUrl)['extension'];
            copy($imgUrl, $this->publicDirectory.$this->imgPath.$imgName); // todo move to data service;
            
            preg_match('#(.*".{1}) (.*".{1}) (.*)#', $item['description'], $matches);
            if(isset($matches[1])) $location->setLat((float)$this->locationService->convertCoord($matches[1]));
            if(isset($matches[2])) $location->setLon((float)$this->locationService->convertCoord($matches[2]));
            if(isset($matches[3])) $location->setName(substr(str_replace('"', "''", $matches[3]), 0, 250));

            $location
                ->setSource($this->source)
                ->setPid((int)$item['id'])
                ->setUrl($this->pinBaseUrl.$item['id'])
                ->setImageDirect($this->imgPath.$imgName)
                ->setDescription(substr($item['description'], 0, 250));
            
            $this->locationService->addType($location);
            $this->locationService->addCountry($location);
            
            $this->locationRepository->add($location);
            $this->newPinCount++;
        }
        
        $this->pinCount++;
        $percentage = ($this->pinCount / $this->pinTotal) * 100;
        $this->dataService->writeFile($this->dataDirectory.'pin.txt',$percentage);
    }

    private function error($error) {
        $this->error = $error;
        $this->done();
    }

    private function done() {
        $jsonData = [
            "last_fetched" => date("d/m/Y H:i", time()),
            "board" => $this->boardId,
            "finished" => $this->error ? 'Error' : 'Success',
            "error" => $this->error,
            "total" => $this->pinCount,
            "newpins" => $this->newPinCount,
            "token" => rand()
        ];
        $this->dataService->writeJson($this->dataDirectory.'export.json', $jsonData);
    }
}