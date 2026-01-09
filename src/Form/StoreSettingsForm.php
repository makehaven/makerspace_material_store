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

    $form['limits']['max_tab_amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Tab Amount ($)'),
      '#description' => $this->t('Members will be blocked from adding to their tab if their balance exceeds this amount. Set 0 for no limit.'),
      '#default_value' => $config->get('max_tab_amount') ?: 50,
      '#step' => 0.01,
    ];

    $form['limits']['max_tab_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Tab Age (Days)'),
      '#description' => $this->t('Members will be blocked from adding to their tab if they have pending items older than this many days. Set 0 for no limit.'),
      '#default_value' => $config->get('max_tab_days') ?: 30,
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
      ->set('api_key', $form_state->getValue('api_key'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
