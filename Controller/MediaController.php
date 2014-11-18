<?php

namespace Youwe\MediaBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Annotation\Route;
use Youwe\MediaBundle\Form\Type\MediaType;
use Youwe\MediaBundle\Model\FileInfo;
use Youwe\MediaBundle\Services\MediaService;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Youwe\MediaBundle\Model\Media;
use Youwe\MediaBundle\Services\Utils;

/**
 * Class MediaController
 * @package Youwe\MediaBundle\Controller
 */
class MediaController extends Controller {

    /**
     * @Route(
     *      "/list/{dir_path}",
     *      name="youwe_media_list",
     *      defaults={"dir_path":null},
     *      options={"expose":true},
     *      requirements={"dir_path":".+"}
     * )
     * @param string $dir_path
     * @return Response
     */
    public function listMediaAction($dir_path = null)
    {
        $parameters = $this->container->getParameter('youwe_media');
        $media = new Media($parameters, $this->container);
        
        /** @var MediaService $service */
        $service = $this->get('youwe.media.service');
        
        $service->setMedia($media);
        $media->setDirPaths($service, $dir_path);
        $form = $this->createForm(new MediaType);
        try{
            $service->handleFormSubmit($form);
        } catch(\Exception $e){
            $response = new Response();
            $response->setContent($e->getMessage());
            $response->setStatusCode($e->getCode() == null ? 500 : $e->getCode());
            return $response;
        }

        $renderParameters = $service->getRenderOptions($form);

        return $this->render($media->getTemplate(), $renderParameters);
    }

    /**
     * @Route("/delete", name="youwe_media_delete", options={"expose":true})
     * @Method("POST")
     *
     * @param Request $request
     * @throws \Exception
     * @return bool
     */
    public function deleteFileAction(Request $request)
    {
        $parameters = $this->container->getParameter('youwe_media');
        $media = new Media($parameters, $this->container);

        /** @var MediaService $service */
        $service = $this->get('youwe.media.service');
        $service->setMedia($media);

        $response = new Response();

        $media->resolveRequest($request);
        try{
            $dir = $service->getFilePath($media);
            $media->setDir($dir);
            $service->checkToken($request->get('token'));
            $media->deleteFile();
        } catch(\Exception $e){
            $response->setContent($e->getMessage());
            $response->setStatusCode($e->getCode() == null ? 500 : $e->getCode());
        }
        return $response;
    }

    /**
     * @Route("/move", name="youwe_media_move", options={"expose":true})
     * @Method("POST")
     *
     * @param Request $request
     * @throws \Exception
     * @return bool
     */
    public function moveFileAction(Request $request)
    {
        $parameters = $this->container->getParameter('youwe_media');
        $media = new Media($parameters, $this->container);

        /** @var MediaService $service */
        $service = $this->get('youwe.media.service');
        $service->setMedia($media);

        $response = new Response();

        $media->resolveRequest($request);

        try{
            $dir = $service->getFilePath($media);
            $media->setDir($dir);
            $service->checkToken($request->get('token'));
            $service->checkPath($dir);
            $media->moveFile();
        } catch(\Exception $e){
            $response->setContent($e->getMessage());
            $response->setStatusCode($e->getCode() == null ? 500 : $e->getCode());
        }

        return $response;
    }


    /**
     * @Route("/copy/{type}", name="youwe_media_copy", options={"expose":true})
     * @Method("POST")
     *
     * @param Request $request
     * @param type - copy or cut
     * @throws \Exception
     * @return bool
     */
    public function copyFileAction(Request $request, $type)
    {
        $parameters = $this->container->getParameter('youwe_media');
        $media = new Media($parameters, $this->container);

        /** @var MediaService $service */
        $service = $this->get('youwe.media.service');
        $service->setMedia($media);

        $response = new Response();

        $media->resolveRequest($request);
        $cut = false;
        if($type === 'cut'){
            $cut = true;
        }

        try{
            $dir = $service->getFilePath($media);
            $service->checkToken($request->get('token'));
            $sources = array(
                'display_dir' => $media->getDirPath(),
                'source_dir' => $dir,
                'source_file' => $media->getFilename(),
                'cut' => $cut
            );
            $this->get('session')->set('copy', $sources);
        } catch(\Exception $e){
            $response->setContent($e->getMessage());
            $response->setStatusCode($e->getCode() == null ? 500 : $e->getCode());
        }

        return $response;
    }

    /**
     * @Route("/paste", name="youwe_media_paste", options={"expose":true})
     * @Method("POST")
     *
     * @param Request $request
     * @throws \Exception
     * @return bool
     */
    public function pasteFileAction(Request $request)
    {
        $response = new Response();
        $copy_session = $this->get('session')->get('copy');
        if(is_null($copy_session)){
            return $response;
        }

        $parameters = $this->container->getParameter('youwe_media');
        $media = new Media($parameters, $this->container);

        /** @var MediaService $service */
        $service = $this->get('youwe.media.service');
        $service->setMedia($media);

        $media->resolveRequest($request);

        try{
            $dir = $service->getFilePath($media);
            $media->setDir($dir);
            $service->checkToken($request->get('token'));
            $sources = $this->get('session')->get('copy');

            $filename = $sources['source_file'];
            $filepath = $sources['source_dir'];
            $media->setTargetFilepath($dir);
            $media->setTargetFilename($filename);
            $media->setFilename($filename);
            $media->setFilepath($filepath);
            $type = $sources['cut'];
            $media->pasteFile($type);
            $this->get('session')->remove('copy');
        } catch(\Exception $e){
            $response->setContent($e->getMessage());
            $response->setStatusCode($e->getCode() == null ? 500 : $e->getCode());
        }

        return $response;
    }

    /**
     * @Route("/extract", name="youwe_media_extract", options={"expose":true})
     * @Method("POST")
     *
     * @param Request $request
     * @throws \Exception
     * @return bool
     */
    public function extractZipAction(Request $request)
    {
        $parameters = $this->container->getParameter('youwe_media');
        $media = new Media($parameters, $this->container);

        /** @var MediaService $service */
        $service = $this->get('youwe.media.service');
        $service->setMedia($media);

        $response = new Response();

        $media->resolveRequest($request);

        try{
            $dir = $service->getFilePath($media);
            $media->setDir($dir);
            $service->checkToken($request->get('token'));
            $media->extractZip();
        } catch(\Exception $e){
            $response->setContent($e->getMessage());
            $response->setStatusCode($e->getCode() == null ? 500 : $e->getCode());
        }
        return $response;
    }

    /**
     * @Route("/fileinfo", name="youwe_media_fileinfo", options={"expose":true})
     *
     * @param Request $request
     * @throws \Exception
     * @return bool
     */
    public function FileInfoAction(Request $request)
    {
        $parameters = $this->container->getParameter('youwe_media');
        $media = new Media($parameters, $this->container);

        /** @var MediaService $service */
        $service = $this->get('youwe.media.service');
        $service->setMedia($media);

        $root = explode(DIRECTORY_SEPARATOR, $media->getUploadPath());
        $web_root = end($root);

        $media->resolveRequest($request);

        try{
            $response = new JsonResponse();
            $dir = $service->getFilePath($media);

            $filepath = $dir . DIRECTORY_SEPARATOR . $media->getFilename();
//            $fileInfo = new FileInfo($media->getPath($media->getDirPath(), $media->getFilename(), true));
            $file_size = Utils::readableSize(filesize($filepath));
            $file_modification = date("Y-m-d H:i:s", filemtime($filepath));
            $mimetype = mime_content_type($filepath);

            $file_info = array();
            $file_info['filename'] = $media->getPath($media->getDirPath(), $media->getFilename());
            $file_info['mimetype'] = $mimetype;
            $file_info['modified'] = $file_modification;
            $file_info['filesize'] = $file_size;
            $file_info['usages'] = $service->getUsages($media->getFilename(), $media->getDirPath());
            $file_info['filepath'] = $web_root . DIRECTORY_SEPARATOR . $media->getDirPath();

            $response->setData(json_encode($file_info));
        } catch(\Exception $e){
            $response = new Response();
            $response->setContent($e->getMessage());
            $response->setStatusCode($e->getCode() == null ? 500 : $e->getCode());
        }
        return $response;
    }

    /**
     * @author Jim Ouwerkerk
     * @Route("/download/{path}", name="youwe_media_download", requirements={"path"=".+"}, options={"expose":true})
     *
     * @return Response
     * @throws \Exception
     */
    public function DownloadAction($path)
    {
        $parameters = $this->container->getParameter('youwe_media');
        $media = new Media($parameters, $this->container);

        $web_path = $media->getPath($path, null, true);
        $content = file_get_contents($web_path);
        $filename = basename($path);
        $response = new Response();

        $response->headers->set('Content-Type', 'mime/type');
        $response->headers->set('Content-Disposition', 'attachment;filename="'.$filename);
        $response->setContent($content);
        return $response;
    }
}