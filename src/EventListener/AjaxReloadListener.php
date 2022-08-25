<?php

namespace Codefog\HasteBundle\EventListener;

use Codefog\HasteBundle\AjaxReloadManager;
use Contao\ContentModel;
use Contao\CoreBundle\ServiceAnnotation\Hook;
use Contao\ModuleModel;
use Symfony\Component\Asset\Packages;
use Symfony\Component\HttpFoundation\RequestStack;

class AjaxReloadListener
{
    public function __construct(
        private readonly AjaxReloadManager $manager,
        private readonly Packages $packages,
        private readonly RequestStack $requestStack,
    ) {}

    /**
     * @Hook("getContentElement")
     */
    public function onGetContentElement(ContentModel $model, string $buffer): string
    {
        // Subscribe the content element if the included frontend module has subscribed itself
        if ($model->type === 'module' && $this->manager->isRegistered(AjaxReloadManager::TYPE_MODULE, (int) $model->module)) {
            $this->manager->subscribe(AjaxReloadManager::TYPE_CONTENT, (int) $model->id, $this->manager->getEvents(AjaxReloadManager::TYPE_MODULE, (int) $model->module));
        }

        // Subscribe the content element if the included content element has subscribed itself
        if ($model->type === 'alias' && $this->manager->isRegistered(AjaxReloadManager::TYPE_CONTENT, (int) $model->cteAlias)) {
            $this->manager->subscribe(AjaxReloadManager::TYPE_CONTENT, (int) $model->id, $this->manager->getEvents(AjaxReloadManager::TYPE_CONTENT, (int) $model->cteAlias));
        }

        $event = $this->getEventFromCurrentRequest();
        $isAjax = $event !== null;
        $buffer = $this->manager->updateBuffer(AjaxReloadManager::TYPE_CONTENT, (int) $model->id, $buffer, $isAjax);

        if ($isAjax) {
            $this->manager->storeBuffer(AjaxReloadManager::TYPE_CONTENT, (int) $model->id, $event, $buffer);
        }

        return $buffer;
    }

    /**
     * @Hook("getFrontendModule")
     */
    public function onGetFrontendModule(ModuleModel $model, string $buffer): string
    {
        $event = $this->getEventFromCurrentRequest();
        $isAjax = $event !== null;
        $buffer = $this->manager->updateBuffer(AjaxReloadManager::TYPE_MODULE, (int) $model->id, $buffer, $isAjax);

        if ($isAjax) {
            $this->manager->storeBuffer(AjaxReloadManager::TYPE_MODULE, (int) $model->id, $event, $buffer);
        }

        return $buffer;
    }

    /**
     * @Hook("modifyFrontendPage")
     */
    public function onModifyFrontendPage(string $buffer,  string $template): string
    {
        if (str_starts_with($template, 'fe_')) {
            if (($response = $this->manager->getResponse()) !== null) {
                $response->send();
            }

            $request = $this->requestStack->getCurrentRequest();

            if ($this->manager->hasListeners()) {
                $buffer = str_replace(
                    '</body>',
                    sprintf('<script src="%s"></script></body>', $this->packages->getUrl('ajax-reload.js', 'codefog_haste')),
                    $buffer
                );

                // Make sure the request is not cached by the browser alongside with the initial request
                $request->headers->set('Vary', 'Haste-Ajax-Reload');
            }
        }

        return $buffer;
    }

    /**
     * Get the event from the current request.
     */
    private function getEventFromCurrentRequest(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request === null || !$request->isXmlHttpRequest() || !$request->server->has('HTTP_HASTE_AJAX_RELOAD')) {
            return null;
        }

        return $request->server->get('HTTP_HASTE_AJAX_RELOAD');
    }
}
