<?php
// Dane dla tests/Browser/admin_ui.py w folderze /ui-test; argument "cleanup" tylko sprząta.
chdir(dirname(__DIR__, 2)); include 'vendor/autoload.php';
\Pimcore\Bootstrap::setProjectRoot(); \Pimcore\Bootstrap::bootstrap(); \Pimcore\Bootstrap::kernel();
use Pimcore\Db;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\Product;
use Pimcore\Model\Element\Editlock;
use Pimcore\Model\Version;

$old = DataObject::getByPath('/ui-test');
if ($old) {
    $ids = Db::get()->fetchFirstColumn(
        'SELECT id FROM objects WHERE id = ? OR path LIKE ?',
        [$old->getId(), $old->getRealFullPath() . '/%']
    );
    $old->delete();

    // Pimcore usuwa wersje asynchronicznie (VersionDeleteMessage), a blokady edycji zdejmuje dopiero przy
    // zamknięciu zakładki w adminie. Przeglądarka testu znika bez tego, więc sprzątamy jawnie.
    foreach ($ids as $id) {
        Editlock::unlock((int) $id, 'object');
        $versions = (new Version\Listing())->setLoadAutoSave(true); // core pomija autosave przy kasowaniu wersji
        $versions->setCondition('cid = ? AND ctype = ?', [(int) $id, 'object']);
        foreach ($versions->load() as $version) {
            $version->delete();
        }
    }
}
if (($argv[1] ?? '') === 'cleanup') { echo "{}"; exit; }

$root = DataObject\Service::createFolderByPath('/ui-test');
$sofa = Product::create(['key'=>'sofa','parentId'=>$root->getId(),'sku'=>'UI-SOFA','published'=>true]);
$sofa->setName('Comfort Sofa','en'); $sofa->save();
$v = Product::create(['key'=>'velvet','parentId'=>$sofa->getId(),'type'=>'variant','sku'=>'UI-SOFA-V','stock'=>0,'published'=>true]); $v->save();
$chair = Product::create(['key'=>'chair','parentId'=>$root->getId(),'sku'=>'UI-CHAIR','published'=>true]); $chair->save();
$draft = Product::create(['key'=>'draft','parentId'=>$root->getId(),'sku'=>'UI-DRAFT','published'=>false]); $draft->save();
echo json_encode(['sofa'=>$sofa->getId(),'variant'=>$v->getId(),'chair'=>$chair->getId(),'draft'=>$draft->getId()]);
