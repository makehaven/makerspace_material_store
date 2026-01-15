<?php

namespace Drupal\makerspace_material_store\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings form for Makerspace Material Store.
 */
class StoreSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'makerspace_material_store_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['makerspace_material_store.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('makerspace_material_store.settings');

    $form['paypal_business_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('PayPal Business ID / Email'),
      '#default_value' => $config->get('paypal_business_id'),
      '#required' => TRUE,
    ];

    $form['return_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Return URL (Success)'),
      '#description' => $this->t('URL to redirect to after successful payment.'),
      '#default_value' => $config->get('return_url'),
    ];

    $form['cancel_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cancel URL'),
      '#default_value' => $config->get('cancel_url'),
    ];

    $form['notify_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Notify URL (IPN)'),
      '#default_value' => $config->get('notify_url') ?: \Drupal::request()->getSchemeAndHttpHost() . '/store/purchase-listener/paypal/ipn',
    ];

    $form['limits'] = [
      '#type' => 'details',
      '#title' => $this->t('Tab Limits & Encouragement'),
      '#open' => TRUE,
    ];

    $default_amount = $config->get('max_tab_amount');
    if ($default_amount === NULL || $default_amount === '') {
      $default_amount = 250;
    }

    $default_days = $config->get('max_tab_days');
    if ($default_days === NULL || $default_days === '') {
      $default_days = 90;
    }

    $form['limits']['max_tab_amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Tab Amount ($)'),
      '#description' => $this->t('Members will be blocked from adding to their tab if their balance exceeds this amount. Set 0 for no limit.'),
      '#default_value' => $default_amount,
      '#step' => 0.01,
    ];

    $form['limits']['max_tab_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Tab Age (Days)'),
      '#description' => $this->t('Members will be blocked from adding to their tab if they have pending items older than this many days. Set 0 for no limit.'),
      '#default_value' => $default_days,
    ];

    $form['stripe_tab'] = [
      '#type' => 'details',
      '#title' => $this->t('Stripe Tab Settings'),
      '#open' => TRUE,
    ];

    $form['stripe_tab']['require_stripe_for_tab'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require a Stripe account to use tabs'),
      '#default_value' => (bool) $config->get('require_stripe_for_tab'),
      '#description' => $this->t('If enabled, users without a Stripe customer ID cannot add to their tab (PayPal checkout remains available).'),
    ];

    $form['stripe_tab']['require_terms_acceptance'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require terms acceptance before using a tab'),
      '#default_value' => $config->get('require_terms_acceptance') === NULL ? TRUE : (bool) $config->get('require_terms_acceptance'),
    ];

    $form['stripe_tab']['store_tab_terms_message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Tab Terms Message'),
      '#default_value' => $config->get('store_tab_terms_message') ?: $this->t('I agree that my account will be charged automatically periodically for items in my tab.'),
      '#description' => $this->t('Shown the first time someone tries to use a tab.'),
      '#states' => [
        'visible' => [
          ':input[name="require_terms_acceptance"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['stripe_tab']['stripe_webhook_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Stripe Webhook Secret'),
      '#default_value' => $config->get('stripe_webhook_secret'),
      '#description' => $this->t('Signing secret for the store tab webhook endpoint.'),
    ];

    $form['ux'] = [
      '#type' => 'details',
      '#title' => $this->t('User Experience'),
      '#open' => TRUE,
    ];

    $form['ux']['post_add_redirect'] = [
      '#type' => 'select',
      '#title' => $this->t('Redirect After Adding to Tab'),
      '#options' => [
        'store' => $this->t('Store Front (/store)'),
        'item' => $this->t('Stay on Item Page'),
        'cart' => $this->t('Go to Tab/Cart'),
      ],
      '#default_value' => $config->get('post_add_redirect') ?: 'store',
      '#description' => $this->t('Where to send the user after they successfully add an item to their tab via the modal or direct link.'),
    ];

    $form['api'] = [
      '#type' => 'details',
      '#title' => $this->t('API Integration'),
      '#description' => $this->t('Settings for workstation software to add items to tabs via API.'),
      '#open' => FALSE,
    ];

    $form['api']['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Store API Key'),
      '#description' => $this->t('Use this key in the "X-Store-API-Key" header or as a query parameter when calling the API.'),
      '#default_value' => $config->get('api_key'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('makerspace_material_store.settings')
      ->set('paypal_business_id', $form_state->getValue('paypal_business_id'))
      ->set('return_url', $form_state->getValue('return_url'))
      ->set('cancel_url', $form_state->getValue('cancel_url'))
      ->set('notify_url', $form_state->getValue('notify_url'))
      ->set('max_tab_amount', $form_state->getValue('max_tab_amount'))
      ->set('max_tab_days', $form_state->getValue('max_tab_days'))
      ->set('require_stripe_for_tab', (bool) $form_state->getValue('require_stripe_for_tab'))
      ->set('require_terms_acceptance', (bool) $form_state->getValue('require_terms_acceptance'))
      ->set('store_tab_terms_message', $form_state->getValue('store_tab_terms_message'))
      ->set('stripe_webhook_secret', $form_state->getValue('stripe_webhook_secret'))
      ->set('post_add_redirect', $form_state->getValue('post_add_redirect'))
      ->set('api_key', $form_state->getValue('api_key'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
