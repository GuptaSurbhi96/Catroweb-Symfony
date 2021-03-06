<?php

namespace Catrobat\AppBundle\Controller\Web;

use Catrobat\AppBundle\Entity\Program;
use Catrobat\AppBundle\Entity\ProgramInappropriateReport;
use Catrobat\AppBundle\Entity\User;
use Catrobat\AppBundle\Entity\UserComment;
use Doctrine\Common\Collections\Criteria;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\HttpFoundation\Request;

class ProgramController extends Controller
{
    /**
     * @Route("/program/{id}", name="program", requirements={"id":"\d+"})
     * @Route("/details/{id}", name="catrobat_web_detail", requirements={"id":"\d+"})
     * @Method({"GET"})
     */
    public function programAction(Request $request, $id, $flavor = 'pocketcode') {
        /**
         * @var $user User
         * @var $program Program
         * @var $reported_program ProgramInappropriateReport
         * @var $gamejam GameJam
         */
        $program = $this->get('programmanager')->find($id);
        $screenshot_repository = $this->get('screenshotrepository');
        $elapsed_time = $this->get('elapsedtime');

        if (!$program || !$program->isVisible()) {
            throw $this->createNotFoundException('Unable to find Project entity.');
        }

        $viewed = $request->getSession()->get('viewed', array());
        $this->checkAndAddViewed($request, $program, $viewed);
        $referrer = $request->headers->get('referer');
        $request->getSession()->set('referer', $referrer);

        $program_comments = $this->findCommentsById($program);
        $program_details = $this->createProgramDetailsArray($screenshot_repository, $program, $elapsed_time,
            $referrer, $program_comments, $request);

        $user = $this->getUser();
        $nolb_status = false;
        $user_name = "";

        if($user != null){
            $nolb_status = $user->getNolbUser();
            $user_name = $user->getUsername();
        }

        $user_programs = $this->findUserPrograms($user, $program);

        $isReportedByUser = $this->checkReportedByUser($program, $user);

        $program_url = $this->generateUrl('program', array('id' => $program->getId()), true);
        $share_text = trim($program->getName() . ' on ' . $program_url . ' ' . $program->getDescription());

        $jam = $this->extractGameJamConfig();
        return $this->get('templating')->renderResponse('::program.html.twig', array(
            'program' => $program,
            'program_details' => $program_details,
            'my_program' => count($user_programs) > 0 ? true : false,
            'already_reported' => $isReportedByUser,
            'shareText' => $share_text,
            'program_url' => $program_url,
            'jam' => $jam,
            'nolb_status' => $nolb_status,
            'user_name' => $user_name,
        ));
    }

    /**
     * @Route("/search/{q}", name="search", requirements={"q":".+"})
     * @Route("/search/", name="empty_search", defaults={"q":null})
     * @Method({"GET"})
     */
    public function searchAction($q) {
        return $this->get('templating')->renderResponse('::search.html.twig', array('q' => $q));
    }

    /**
     * @Route("/profileDeleteProgram/{id}", name="profile_delete_program", requirements={"id":"\d+"}, defaults={"id" = 0})
     * @Method({"GET"})
     */
    public function deleteProgramAction($id) {
        /*
       * @var $user \Catrobat\AppBundle\Entity\User
       * @var $program \Catrobat\AppBundle\Entity\Program
       */
        if ($id == 0) {
            return $this->redirectToRoute('profile');
        }

        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('fos_user_security_login');
        }

        $programs = $user->getPrograms()->matching(Criteria::create()
            ->where(Criteria::expr()->eq('id', $id)));

        $program = $programs[0];
        if (!$program) {
            throw $this->createNotFoundException('Unable to find Project entity.');
        }

        $program->setVisible(false);

        $em = $this->getDoctrine()->getManager();
        $em->persist($program);
        $em->flush();

        return $this->redirectToRoute('profile');
    }

    /**
     * @Route("/profileToggleProgramVisibility/{id}", name="profile_toggle_program_visibility", requirements={"id":"\d+"}, defaults={"id" = 0})
     * @Method({"GET"})
     */
    public function toggleProgramVisibilityAction($id) {
        /*
         * @var $user \Catrobat\AppBundle\Entity\User
         * @var $program \Catrobat\AppBundle\Entity\Program
         */

        if ($id == 0) {
            return $this->redirectToRoute('profile');
        }

        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('fos_user_security_login');
        }

        $programs = $user->getPrograms()->matching(Criteria::create()
            ->where(Criteria::expr()->eq('id', $id)));

        $program = $programs[0];
        if (!$program) {
            throw $this->createNotFoundException('Unable to find Project entity.');
        }

        $program->setPrivate(!$program->getPrivate());

        $em = $this->getDoctrine()->getManager();
        $em->persist($program);
        $em->flush();

        return $this->redirectToRoute('profile');
    }

    /**
     * @return array
     */
    private function extractGameJamConfig() {
        $jam = null;
        $gamejam = $this->get('gamejamrepository')->getCurrentGameJam();

        if ($gamejam) {
            $gamejam_flavor = $gamejam->getFlavor();
            if ($gamejam_flavor != null) {
                $config = $this->container->getParameter('gamejam');
                $gamejam_config = $config[$gamejam_flavor];
                if ($gamejam_config) {
                    $logo_url = $gamejam_config['logo_url'];
                    $display_name = $gamejam_config['display_name'];
                    $gamejam_url = $gamejam_config['gamejam_url'];
                    $jam = array(
                        'name' => $display_name,
                        'logo_url' => $logo_url,
                        'gamejam_url' => $gamejam_url
                    );
                }
            }
        }
        return $jam;
    }

    /**
     * @param Request $request
     * @param $program
     * @param $viewed
     */
    private function checkAndAddViewed(Request $request, $program, $viewed) {
        if (!in_array($program->getId(), $viewed)) {
            $this->get('programmanager')->increaseViews($program);
            $viewed[] = $program->getId();
            $request->getSession()->set('viewed', $viewed);
        }
    }

    /**
     * @param $screenshot_repository
     * @param $program
     * @param $elapsed_time
     * @param $referrer
     * @param $program_comments
     * @return array
     */
    private function createProgramDetailsArray($screenshot_repository, $program, $elapsed_time, $referrer, $program_comments, $request) {

        $rec_from_id = intval($request->query->get('rec_from',0));
        if ($rec_from_id > 0)
            $url = $this->generateUrl('download', array('id' => $program->getId(), 'rec_from' => $rec_from_id,  'fname' => $program->getName()));
        else
            $url = $this->generateUrl('download', array('id' => $program->getId(), 'fname' => $program->getName()));

        $program_details = array(
            'screenshotBig' => $screenshot_repository->getScreenshotWebPath($program->getId()),
            'downloadUrl' => $url,
            'languageVersion' => $program->getLanguageVersion(),
            'downloads' => $program->getDownloads() + $program->getApkDownloads(),
            'views' => $program->getViews(),
            'filesize' => sprintf('%.2f', $program->getFilesize() / 1048576),
            'age' => $elapsed_time->getElapsedTime($program->getUploadedAt()->getTimestamp()),
            'referrer' => $referrer,
            'id' => $program->getId(),
            'comments' => $program_comments,
            'commentsLength' => count($program_comments),
            'isAdmin' => $this->isGranted("ROLE_ADMIN"),
        );
        return $program_details;
    }

    /**
     * @param $program
     * @return array|\Catrobat\AppBundle\Entity\UserComment[]
     */
    private function findCommentsById($program) {
        $program_comments = $this->getDoctrine()
            ->getRepository('AppBundle:UserComment')
            ->findBy(
                array('programId' => $program->getId()), array('id' => 'DESC'));
        return $program_comments;
    }

    /**
     * @param $user
     * @param $program
     * @return null
     */
    private function findUserPrograms($user, $program) {
        $user_programs = null;
        if ($user) {
            $user_programs = $user->getPrograms()->matching(Criteria::create()
                ->where(Criteria::expr()->eq('id', $program->getId())));
        }
        return $user_programs;
    }

    /**
     * @param $program
     * @param $user
     * @return bool
     */
    private function checkReportedByUser($program, $user) {
        $isReportedByUser = false;
        $em = $this->getDoctrine()->getManager();
        $reported_program = $em->getRepository("\Catrobat\AppBundle\Entity\ProgramInappropriateReport")
            ->findOneBy(array('program' => $program->getId()));

        if ($reported_program) {
            $isReportedByUser = ($user == $reported_program->getReportingUser());
        }
        return $isReportedByUser;
    }
}
