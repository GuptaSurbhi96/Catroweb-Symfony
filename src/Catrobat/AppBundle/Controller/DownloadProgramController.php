<?php

namespace Catrobat\AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Catrobat\AppBundle\Entity\ProgramManager;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Catrobat\AppBundle\Services\ProgramFileRepository;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class DownloadProgramController extends Controller
{
    /**
     * @Route("/download/{id}.catrobat", name="download", options={"expose"=true}, defaults={"_format": "json"})
     * @Method({"GET"})
     */
    public function downloadProgramAction(Request $request, $id) {
        /* @var $program_manager ProgramManager */
        /* @var $file_repository ProgramFileRepository */

        $referrer = $request->getSession()->get('referer');
        $program_manager = $this->get('programmanager');
        $file_repository = $this->get('filerepository');

        $program = $program_manager->find($id);
        if (!$program) {
            throw new NotFoundHttpException();
        }
        if (!$program->isVisible()) {
            throw new NotFoundHttpException();
        }

        $rec_from_id = intval($request->query->get('rec_from',0));

        $file = $file_repository->getProgramFile($id);
        if ($file->isFile()) {
            $downloaded = $request->getSession()->get('downloaded', array());
            if (!in_array($program->getId(), $downloaded)) {
                $this->get('programmanager')->increaseDownloads($program);
                $downloaded[] = $program->getId();
                $request->getSession()->set('downloaded', $downloaded);
                $request->attributes->set('download_statistics_program_id', $id);
                $request->attributes->set('referrer', $referrer);
                if ($rec_from_id > 0)
                    $request->attributes->set('rec_from', $rec_from_id);
            }

            $response = new BinaryFileResponse($file);
            $d = $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $program->getId() . '.catrobat'
            );
            $response->headers->set('Content-Disposition', $d);

            return $response;
        }
        throw new NotFoundHttpException();
    }
}
