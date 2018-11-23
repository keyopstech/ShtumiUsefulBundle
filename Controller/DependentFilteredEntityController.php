<?php

namespace Shtumi\UsefulBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Translation\TranslatorInterface;

use Symfony\Component\HttpFoundation\Response;


class DependentFilteredEntityController extends Controller
{
    /**
     * @var array
     */
    private $options = array();

    /**
     * @var TranslatorInterfacde $translator
     */
    private $translator;

    /**
     * @var string
     */
    private $html;

    /**
     * @param Request $request
     * @return Response
     * @throws \Exception
     */
    public function getOptionsAction(Request $request)
    {
        $this->fetchOptionsInContainer($request);
        $this->translator = $this->get('translator');

        if ($this->options['entity_inf']['role'] !== 'IS_AUTHENTICATED_ANONYMOUSLY') {
            if (false === $this->get('security.context')->isGranted($this->options['entity_inf']['role'])) {
                throw new AccessDeniedException();
            }
        }

        if (null !== $this->options['entity_inf']['callback'] && preg_match('/^(.*)::([a-z]*)$/i', $this->options['entity_inf']['callback'], $fcdnMatches) === 1) {
            $results = $this->getResultsWithPersonnalizedCallback($fcdnMatches, $this->options['parent_id']);
        } else {
            $results = $this->getResultsWithQueryBuilderAndPotentiallyRepositoryCallBack($this->options['entity_inf'], $this->options['parent_id']);
        }

        if (empty($results)) {
            return new Response('<option value="">' . $this->translator->trans($this->options['entity_inf']['no_result_msg']) . '</option>');
        }

        $this->makeHtmlWithResults($results);

        return new Response($this->html);
    }

    /**
     * @param array $results
     */
    private function makeHtmlWithResults(array $results)
    {
        if ($this->options['empty_value'] !== false)
            $this->html .= '<option value="">' . $this->translator->trans($this->options['empty_value']) . '</option>';

        $this->transformResultsInOptions($results);
    }


    /**
     * @param array $results
     * @return void
     */
    private function transformResultsInOptions($results)
    {
        foreach ($results as $key => $result) {
            if (is_array($result)) {
                $this->html .= '<optgroup label="' . $key . '">';
                $this->transformResultsInOptions($result);
                $this->html .= '</optgroup>';
            }

            if(is_object($result)) {
                if ($this->options['entity_inf']['property']) {
                    $getter = $this->options['getterName'];
                    $res = $result->$getter();
                } else {
                    $res = (string)$result;
                }

                $res = $this->options['translateValue'] ? $res : $this->get('translator')->trans($res);
                $this->html .= '<option value="' . $result->getId() . '">' . $res . '</option>';

            }
        }
    }


    /**
     * @param Request $request
     */
    private function fetchOptionsInContainer(Request $request)
    {
        $this->options['entity_alias'] = $request->get('entity_alias');
        $this->options['parent_id'] = $request->get('parent_id');
        $this->options['empty_value'] = $request->get('empty_value');
        $this->options['translateValue'] = $request->get('translate_value');
        $this->options['entities'] = $this->get('service_container')->getParameter('shtumi.dependent_filtered_entities');
        $this->options['entity_inf'] = $this->options['entities'][$request->get('entity_alias')];
        $this->options['getterName'] = $this->getGetterName($this->options['entity_inf']['property']);
    }



    private function getResultsWithQueryBuilderAndPotentiallyRepositoryCallBack($entity_inf, $parent_id)
    {
        $qb = $this->getDoctrine()
            ->getRepository($entity_inf['class'])
            ->createQueryBuilder('e')
            ->where('e.' . $entity_inf['parent_property'] . ' = :parent_id')
            ->orderBy('e.' . $entity_inf['order_property'], $entity_inf['order_direction'])
            ->setParameter('parent_id', $parent_id);


        if (null !== $entity_inf['callback']) {
            $repository = $qb->getEntityManager()->getRepository($entity_inf['class']);

            if (!method_exists($repository, $entity_inf['callback'])) {
                throw new \InvalidArgumentException(sprintf('Callback function "%s" in Repository "%s" does not exist.', $entity_inf['callback'], get_class($repository)));
            }

            call_user_func(array($repository, $entity_inf['callback']), $qb);
        }

        return $qb->getQuery()->getResult();

    }


    public function getJSONAction()
    {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->get('doctrine.orm.entity_manager');
        $request = $this->get('request');

        $entity_alias = $request->get('entity_alias');
        $parent_id = $request->get('parent_id');
        $empty_value = $request->get('empty_value');

        $entities = $this->get('service_container')->getParameter('shtumi.dependent_filtered_entities');
        $entity_inf = $entities[$entity_alias];

        if ($entity_inf['role'] !== 'IS_AUTHENTICATED_ANONYMOUSLY') {
            if (false === $this->get('security.context')->isGranted($entity_inf['role'])) {
                throw new AccessDeniedException();
            }
        }

        $term = $request->get('term');
        $maxRows = $request->get('maxRows', 20);

        $like = '%' . $term . '%';

        $property = $entity_inf['property'];
        if (!$entity_inf['property_complicated']) {
            $property = 'e.' . $property;
        }

        $qb = $em->createQueryBuilder()
            ->select('e')
            ->from($entity_inf['class'], 'e')
            ->where('e.' . $entity_inf['parent_property'] . ' = :parent_id')
            ->setParameter('parent_id', $parent_id)
            ->orderBy('e.' . $entity_inf['order_property'], $entity_inf['order_direction'])
            ->setParameter('like', $like)
            ->setMaxResults($maxRows);

        if ($entity_inf['case_insensitive']) {
            $qb->andWhere('LOWER(' . $property . ') LIKE LOWER(:like)');
        } else {
            $qb->andWhere($property . ' LIKE :like');
        }

        $results = $qb->getQuery()->getResult();

        $res = array();
        foreach ($results AS $r) {
            $res[] = array(
                'id' => $r->getId(),
                'text' => (string)$r
            );
        }

        return new Response(json_encode($res));
    }

    private function getGetterName($property)
    {
        $name = "get";
        $name .= mb_strtoupper($property[0]) . substr($property, 1);

        while (($pos = strpos($name, '_')) !== false) {
            $name = substr($name, 0, $pos) . mb_strtoupper(substr($name, $pos + 1, 1)) . substr($name, $pos + 2);
        }

        return $name;
    }

    /**
     * @param $fcdnMatches
     * @param $parent_id
     * @return mixed
     * @throws \Exception
     */
    private function getResultsWithPersonnalizedCallback($fcdnMatches, $parent_id)
    {
        if (!$this->has($fcdnMatches[1])) {
            throw new \Exception(
                sprintf('Controller %s must be a public service in container', $fcdnMatches[2])
            );
        }

        if (!method_exists($this->get($fcdnMatches[1]), $fcdnMatches[2])) {
            throw new \Exception(
                sprintf('%s must be a valid public method with one parameter (parent_id)', $fcdnMatches[2])
            );
        }
        $results = call_user_func(array($this->get($fcdnMatches[1]), $fcdnMatches[2]), $parent_id);
        return $results;
    }
}
