<?php
$dir = __DIR__;
$moduleDirName = 'bitrix-2';
$systemDirName = '.system';

function getLastDirName($path)
{
    $explodeDirPath = explode('/', $path);
    return end($explodeDirPath);
}

$rootModuleDir = realpath($dir . '/../');
$allow = false;
if (
    getLastDirName($dir) === $systemDirName
    && getLastDirName($rootModuleDir) === $moduleDirName
) {
    $allow = true;
}

if (
    $allow
    && isset($_REQUEST['mindboxUpdater'])
    && $_REQUEST['mindboxUpdater'] == 'Y'
    && !empty($_REQUEST['mindboxToken'])
    && !empty($_REQUEST['mindboxUpdateCode'])
) {

    $url = 'http://cdn.local/?u=' . $_REQUEST['mindboxUpdateCode'];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: ' . $_REQUEST['mindboxToken']]);
    $rawFileData = curl_exec($ch);

    if (curl_errno($ch)) {
        echo 'error:' . curl_error($ch);
    }
    $requestInfo = curl_getinfo($ch);
    $responseCode = $requestInfo['http_code'];

    curl_close($ch);

    if ($responseCode === 200) {
        if (!empty($rawFileData)) {
            $saveUpdateFile = $dir . '/update-' . $_REQUEST['mindboxUpdateCode'] . '.zip';
            file_put_contents($saveUpdateFile, $rawFileData);

            if (ob_get_level()) {
                ob_end_clean();
            }
            $res = false;

            if (file_exists($saveUpdateFile)) {
                $zip = new \ZipArchive();

                $openZip = $zip->open($saveUpdateFile);

                if ($openZip) {
                    $res = $zip->extractTo($rootModuleDir);
                    $zip->close();
                }
            }

            if ($res) {
                $return = ['success' => true];
            } else {
                $return = ['error' => 'Невозможно распаковать архив'];
            }
            exit;
        }
    } else {

    }

}

?>
<?php if ($allow):?>
<div class="mindbox-dev">
    <input placeholder="Токен клиента" name="mindbox-dev-client-token">
    <input placeholder="Код обновления" name="mindbox-dev-update-code">
    <input type="hidden" name="mindbox-dev-form" value="Y">
    <button class="adm-btn-green mindbox-dev__button">Click</button>
</div>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelector('.mindbox-dev__button').addEventListener('click', function (e) {
      e.preventDefault();
      //this.disabled = true;
      let rootElem = this.closest('.mindbox-dev');
      let token = rootElem.querySelector('[name="mindbox-dev-client-token"]').value;
      let updateCode = rootElem.querySelector('[name="mindbox-dev-update-code"]').value;
      let xhr = new XMLHttpRequest();
      let body = 'mindboxToken=' + encodeURIComponent(token) +
          '&mindboxUpdateCode=' + encodeURIComponent(updateCode) +
          '&mindboxUpdater=' + encodeURIComponent('Y');
      xhr.open("POST", window.location.href, true);
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
      xhr.send(body);
    })
  });
</script>
<?php endif;?>
