<?php

/*
    ***** BEGIN LICENSE BLOCK *****

    This file is part of the Zotero Data Server.

    Copyright © 2017 Center for History and New Media
                     George Mason University, Fairfax, Virginia, USA
                     http://zotero.org

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.

    ***** END LICENSE BLOCK *****
*/

class SnsController extends Controller
{
	// index.php is calling this function. Maybe we should add it to Controller class.
	public function init() {

	}

	protected function register($hash) {
		// We don't need to check the file size, because it's
		// included in file upload signature. We get the file we expected,
		// or we don't get it at all.

		// Everyting is in one transaction to prevent racing conditions with queueUpload
		Zotero_DB::query("SET TRANSACTION ISOLATION LEVEL SERIALIZABLE");
		Zotero_DB::beginTransaction();

		$results = Zotero_Storage::getUploadQueueItemsAndInfo($hash);

		foreach ($results as $result) {
			$info = $result['info'];
			$item = $result['item'];

			$fileInfo = Zotero_Storage::getLocalFileInfo($info);
			if ($fileInfo) {
				$storageFileID = $fileInfo['storageFileID'];
			}
			else {
				$storageFileID = Zotero_Storage::addFile($info);
			}

			Zotero_Storage::updateFileItemInfo($item, $storageFileID, $info, true);
			Zotero_Storage::logUpload($info->userID, $item, $info->uploadKey, IPAddress::getIP());

			// logUpload removes the current storageUploadQueue row (from $info),
			// therefore it will be missing in succeedUploadbyHash
			Z_Core::$MC->set("successfulUploadKey_" . $info->uploadKey, 1, 60);
		}

		Zotero_Storage::succeedUploadsByHash($hash);
		Zotero_DB::commit();
	}

	public function sns() {

		if(!Z_CONFIG::$SNS_USERNAME || !Z_CONFIG::$SNS_PASSWORD) {
			http_response_code(500);
			exit;
		}

		if(!isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
			http_response_code(401);
			exit;
		};

		$username = $_SERVER['PHP_AUTH_USER'];
		$password = $_SERVER['PHP_AUTH_PW'];

		if ($username != Z_CONFIG::$SNS_USERNAME || $password != Z_CONFIG::$SNS_PASSWORD) {
			http_response_code(403);
			exit;
		}

		$json = json_decode(file_get_contents("php://input"));

		if ($json->Type == "Notification") {
			Z_Core::debug("SNS notification: " . $json->Message);
			$parts = explode(':', $json->TopicArn);
			$topic = $parts[5];
			if ($topic == 's3-object-created-'. Z_CONFIG::$S3_BUCKET) {
				$json2 = json_decode($json->Message);
				$hash = $json2->Records[0]->s3->object->key;
				$this->register($hash);
			}
		}
		// This should happen only the first time when SNS is configured
		else if ($json->Type == "SubscriptionConfirmation") {
			Z_Core::logError("Possible repeated SNS subscription");
			$curl_handle = curl_init();
			curl_setopt($curl_handle, CURLOPT_URL, $json->SubscribeURL);
			curl_setopt($curl_handle, CURLOPT_CONNECTTIMEOUT, 2);
			curl_exec($curl_handle);
			curl_close($curl_handle);
		}
		else {
			Z_Core::logError("Unknown SNS type: ".$json->Type);
		}
	}
}
