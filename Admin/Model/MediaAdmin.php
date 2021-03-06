<?php
/**
 * This file is part of the Networking package.
 *
 * (c) net working AG <info@networking.ch>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Networking\InitCmsBundle\Admin\Model;

use Gaufrette\Util;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Route\RouteCollection;
use Sonata\MediaBundle\Admin\BaseMediaAdmin as Admin;
use Sonata\MediaBundle\Form\DataTransformer\ProviderDataTransformer;
use Sonata\MediaBundle\Provider\FileProvider;
use Sonata\MediaBundle\Provider\MediaProviderInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Class MediaAdmin
 * @package Networking\InitCmsBundle\Admin\Model
 * @author Yorkie Chadwick <y.chadwick@networking.ch>
 */
abstract class MediaAdmin extends Admin
{
    /**
     * @var string
     */
    protected $baseRoutePattern = 'cms/media';

    /**
     * @var array
     * @var array
     */
    protected $trackedActions = array('list');

    /**
     * @var array $languages
     */
    protected $languages;

    /**
     * @var array
     */
    protected $localisedMediaProviders = array('sonata.media.provider.file');

    /**
     * Default values to the datagrid.
     *
     * @var array
     */
    protected $datagridValues = array(
        '_page'       => 1,
        '_per_page'   => 50,
        '_sort_order' => 'DESC',
        '_sort_by' => 'createdAt'
    );


    /**
     * Set the language paramenter to contain a list of languages most likely
     * passed from the config.yml file
     *
     * @param array $languages
     */
    public function setLanguages(array $languages)
    {
        $this->languages = $languages;
    }

    /**
     * @return Array
     */
    public function getTrackedActions()
    {
        return $this->trackedActions;
    }

    /**
     * @param $trackedActions
     * @return $this
     */
    public function setTrackedActions($trackedActions)
    {
        $this->trackedActions = $trackedActions;

        return $this;
    }

    /**
     * @return string
     */
    public function getIcon()
    {
        return 'fa-picture-o';
    }

    /**
     * {@inheritdoc}
     */
    public function configureRoutes(RouteCollection $collection)
    {
        parent::configureRoutes($collection);
        $collection->add(
            'init_ckeditor_browser',
            'init_ckeditor_browser',
            array(
                '_controller' => 'NetworkingInitCmsBundle:CkeditorAdmin:browser'
            )
        );

        $collection->add(
            'init_ckeditor_upload_file',
            'init_ckeditor_upload_file',
            array(
                '_controller' => 'NetworkingInitCmsBundle:CkeditorAdmin:upload',
                'type' => 'file',
                'method' => 'POST'
            )
        );

        $collection->add(
            'init_ckeditor_upload_image',
            'init_ckeditor_upload_image',
            array(
                '_controller' => 'NetworkingInitCmsBundle:CkeditorAdmin:upload',
                'type' => 'image',
                'method' => 'POST'
            )
        );

        $collection->add(
            'refresh_list',
            'refresh_list',
            array(
                '_controller' => 'NetworkingInitCmsBundle:MediaAdmin:refreshList',
            )
        );
    }

    /**
     * {@inheritdoc}
     */
    public function buildDatagrid()
    {
        if ($this->datagrid) {
            $this->datagrid = null;
            $this->filterFieldDescriptions = array();
        }



        $filterParameters = $this->getFilterParameters();

        $persistentParameters = $this->getPersistentParameters();
        $context = $persistentParameters['context'];
        $provider = $persistentParameters['provider'];


        if ($context && array_key_exists('context', $filterParameters)) {
            if ($filterParameters['context']['value'] != $context) {
                $filterParameters['context']['value'] = $context;
                $filterParameters['_page'] = 1;
            }
        }else{
            $filterParameters['context'] = array('value' => $persistentParameters['context']);
        }


        if ($provider && array_key_exists('providerName', $filterParameters)) {
            if ($filterParameters['providerName']['value'] != $provider) {
                $filterParameters['providerName']['value'] = $provider;
                $filterParameters['_page'] = 1;
            }
        }elseif($provider){
            $filterParameters['providerName']['value'] = $provider;
            $filterParameters['_page'] = 1;
        }
        else{
            $filterParameters['providerName'] = array('value' => $persistentParameters['provider']);
        }

        $this->request->getSession()->set($this->getCode().'.filter.parameters', $filterParameters);

        // transform _sort_by from a string to a FieldDescriptionInterface for the datagrid.
        if (isset($filterParameters['_sort_by']) && is_string($filterParameters['_sort_by'])) {
            if ($this->hasListFieldDescription($filterParameters['_sort_by'])) {
                $filterParameters['_sort_by'] = $this->getListFieldDescription($filterParameters['_sort_by']);
            } else {
                $filterParameters['_sort_by'] = $this->getModelManager()->getNewFieldDescriptionInstance(
                    $this->getClass(),
                    $filterParameters['_sort_by'],
                    array()
                );

                $this->getListBuilder()->buildField(null, $filterParameters['_sort_by'], $this);
            }
        }

        // initialize the datagrid
        $this->datagrid = $this->getDatagridBuilder()->getBaseDatagrid($this, $filterParameters);

        $this->datagrid->getPager()->setMaxPageLinks($this->maxPageLinks);

        $mapper = new DatagridMapper($this->getDatagridBuilder(), $this->datagrid, $this);


        // build the datagrid filter
        $this->configureDatagridFilters($mapper, $context, $provider);

        // ok, try to limit to add parent filter
        if ($this->isChild() && $this->getParentAssociationMapping() && !$mapper->has(
                $this->getParentAssociationMapping()
            )
        ) {
            $mapper->add(
                $this->getParentAssociationMapping(),
                null,
                array(
                    'field_type' => 'sonata_type_model_reference',
                    'field_options' => array(
                        'model_manager' => $this->getModelManager()
                    ),
                    'operator_type' => 'hidden'
                )
            );
        }

        foreach ($this->getExtensions() as $extension) {
            $extension->configureDatagridFilters($mapper, $context);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper, $context = '', $provider = '')
    {

        $datagridMapper
            ->add('name', 'networking_init_cms_simple_string')
            ->add('tags')
            ->add('authorName', null, array('hidden' => true));

        $datagridMapper->add(
            'context',
            'networking_init_cms_simple_string',
            array('field_type' => 'hidden', 'label_render' => false)
        );

        $datagridMapper->add(
            'providerName',
            'networking_init_cms_simple_string',
            array('field_type' => 'hidden', 'label_render' => false)
        );

    }

    /**
     * {@inheritdoc}
     */
    public function getBatchActions()
    {
        if ($this->request && $this->request->get('pcode') == '') {
            return parent::getBatchActions();
        }

        return array();
    }

    /**
     * {@inheritdoc}
     */
    public function getExportFormats()
    {
        if ($this->request->get('pcode') == '') {
            return parent::getExportFormats();
        }

        return array();
    }

    /**
     * {@inheritdoc}
     */
    public function generateUrl($name, array $parameters = array(), $absolute = false)
    {
        try {
            if ($this->getRequest()->get('pcode')) {
                $parameters['pcode'] = $this->getRequest()->get('pcode');
            }
        } catch (\Exception $e) {
            //do nothing
        }


        return parent::generateUrl($name, $parameters, $absolute);
    }

    /**
     * @param array $providers
     */
    public function setLocalisedMediaProviders(array $providers)
    {
        $this->localisedMediaProviders = $providers;
    }

    /**
     * @param string $name
     * @return null|string|void
     */
    public function getTemplate($name)
    {
        if ($name === 'edit' && is_null($this->getSubject()->getId()) && !$this->request->get('pcode')) {
            $provider = $this->pool->getProvider($this->request->get('provider'));
            if ($provider instanceof FileProvider) {
                return 'NetworkingInitCmsBundle:MediaAdmin:multifileupload_jquery.html.twig';
            }
        }

        return parent::getTemplate($name);
    }

    /**
     * @param mixed $media
     * @return mixed|void
     */
    public function prePersist($media)
    {
        if($checksum = $this->getChecksum($media)){
            $media->setMd5File($checksum);
        }

        return parent::prePersist($media);
    }

    /**
     * @param $media
     * @return string
     */
    public function getChecksum($media)
    {
        if($media->getBinaryContent() instanceof UploadedFile){
            return Util\Checksum::fromFile($media->getBinaryContent()->getPathName());
        }
        return false;
    }

    /**
     * @param mixed $media
     * @return mixed|void
     */
    public function preUpdate($media)
    {
        if($checksum = $this->getChecksum($media)){
            $media->setMd5File($checksum);
        }
        return parent::preUpdate($media);
    }

    /**
     * @param $media
     * @return object
     */
    public function checkForDuplicate($media, $checksum)
    {
        $duplicate = $this->getModelManager()->findOneBy(
            $this->getClass(),
            array(
                'context' => $media->getContext(),
                'md5File' => $checksum
            )
        );

        return $duplicate;

    }

    /**
     * {@inheritdoc}
     */
    protected function configureFormFields(FormMapper $formMapper)
    {
        $media = $this->getSubject();


        if (!$media) {
            $media = $this->getNewInstance();
        }

        if (!$media || !$media->getProviderName()) {
            return;
        }

        $formMapper->getFormBuilder()->addModelTransformer(
            new ProviderDataTransformer($this->pool, $this->getClass()),
            true
        );

        $provider = $this->pool->getProvider($media->getProviderName());

        if ($media->getId()) {
            $this->buildEditForm($formMapper, $provider);
        } else {
            $provider->buildCreateForm($formMapper);
        }


        if (in_array($provider->getName(), $this->localisedMediaProviders)) {
            $formMapper->add(
                'locale',
                'choice',
                array(
                    'choices' => $this->getLocaleChoices(),
                )
            );
        }

            $formMapper->add(
                'tags',
                'sonata_type_model',
                array(
                    'required' => false,
                    'expanded' => false,
                    'multiple' => true,
                    'help_label' => 'help.media_tag',
                    'taggable' => true,
                    'choices_as_values' => true,
                    'attr' => array('style' => "width:220px"),
                )
            );

        //remove and re-add fields to control field order
        if ($formMapper->has('enabled')) {
            $formMapper->remove('enabled');
            $formMapper->add(
                'enabled',
                null,
                array('required' => false),
                array('inline_block' => true)
            );
        }

        if ($formMapper->has('cdnIsFlushable')) {
            $formMapper->remove('cdnIsFlushable');
            $formMapper->add(
                'cdnIsFlushable',
                null,
                array('required' => false),
                array('inline_block' => true)
            );
        }
    }

    /**
     * @param FormMapper $formMapper
     * @param MediaProviderInterface $provider
     */
    protected function buildEditForm(FormMapper $formMapper, MediaProviderInterface $provider)
    {
        $provider->buildEditForm($formMapper);

        if ($formMapper->get('binaryContent')) {
            /** @var \Symfony\Component\Form\FormBuilder $formBuilder */
            $field = $formMapper->get('binaryContent');
            $options = $field->getOptions();
            $label = "form.label_binary_content_new";
            $providerName = $provider->getName();

            if ($providerName == 'sonata.media.provider.image' || $providerName == 'sonata.media.provider.youtube') {
                $previewImageLabel = "form.label_image";
                $label = "form.label_binary_content_image_new";

                if ($providerName == 'sonata.media.provider.youtube') {
                    $previewImageLabel = 'form.label_current_video';
                    $label = "form.label_binary_content_youtube_new";
                }

                $formMapper->remove('binaryContent');
                $formMapper->add(
                    'self',
                    'networking_type_media_preview',
                    array('required' => false, 'label' => $previewImageLabel, 'provider' => $providerName)
                );
            }

            $options['label'] = $label;
            $formMapper->add('binaryContent', $field->getType()->getName(), $options);
        }
    }

    /**
     * Provide an array of locales where the locale is the key and the label is
     * the value for easy display in a dropdown select for example
     * example: array('de_CH' => 'Deutsch', 'en_GB' => 'English')
     *
     * @return array
     */
    protected function getLocaleChoices()
    {
        $locale = array();

        foreach ($this->languages as $language) {
            $locale[$language['locale']] = $language['label'];
        }

        return $locale;
    }

    /**
     * {@inheritdoc}
     */
    protected function configureListFields(ListMapper $listMapper)
    {
        $listMapper
            ->addIdentifier(
                'name',
                'string',
                array(
                    'template' => 'NetworkingInitCmsBundle:MediaAdmin:list_custom.html.twig',
                )
            )
            ->add('createdAt', 'string', array('label' => 'label.created_at'))
            ->add('size', 'string', array('label' => 'label.size'));

        if ($this->request && $this->request->get('pcode') == '') {
            $listMapper->add(
                '_action',
                'actions',
                array(
                    'actions' => array(
                        'show' => array(),
                        'edit' => array(),
                        'delete' => array()
                    )
                )
            );
        }

    }



    /**
     * {@inheritdoc}
     */
    public function getPersistentParameters()
    {
        if (!$this->hasRequest()) {
            return array();
        }

        $filterParameters = $this->getFilterParameters();
        $context   = $this->getRequest()->get('context');
        $provider  = $this->getRequest()->get('provider');

        if(is_array($context) && array_key_exists('value', $context)){
            $context = $context['value'];
        }

        if(!$provider && array_key_exists('providerName', $filterParameters)){
            if(!$provider && !$context){
                $provider = $filterParameters['providerName']['value'];
            }
        }

        if(!$context &&  array_key_exists('context', $filterParameters)){
            $context = $filterParameters['context']['value'];
        }elseif(!$context){
            $context = $this->pool->getDefaultContext();
        }



        $providers = $this->pool->getProvidersByContext($context);


        // if the context has only one provider, set it into the request
        // so the intermediate provider selection is skipped
        if (count($providers) == 1 && null === $provider) {
            $provider = array_shift($providers)->getName();
            $this->getRequest()->query->set('provider', $provider);
        }

        return array(
            'provider' => $provider,
            'context'  => $context,
        );
    }
}
