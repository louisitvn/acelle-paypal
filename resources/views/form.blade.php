<div class="mc-form-group">
    <label class="mc-form-label">{{ trans('paypal::messages.form.client_id') }} <span class="mc-form-required">*</span></label>
    <p class="mc-form-help" style="margin-top:0;margin-bottom:var(--space-1);">
        {{ trans('paypal::messages.form.client_id_help') }}
    </p>
    <input type="text"
           name="gateway_data[client_id]"
           class="mc-form-input @error('gateway_data.client_id') mc-form-input-error @enderror"
           value="{{ old('gateway_data.client_id', $paymentGateway->getGatewayData('client_id')) }}"
           placeholder="ATD3wd2..."
           required>
    @error('gateway_data.client_id')<div class="mc-form-error">{{ $message }}</div>@enderror
</div>

<div class="mc-form-group">
    <label class="mc-form-label">{{ trans('paypal::messages.form.client_secret') }} <span class="mc-form-required">*</span></label>
    <p class="mc-form-help" style="margin-top:0;margin-bottom:var(--space-1);">
        {{ trans('paypal::messages.form.client_secret_help') }}
    </p>
    <input type="password"
           name="gateway_data[client_secret]"
           class="mc-form-input @error('gateway_data.client_secret') mc-form-input-error @enderror"
           value="{{ old('gateway_data.client_secret', $paymentGateway->getGatewayData('client_secret')) }}"
           placeholder="EJlMTW..."
           required>
    @error('gateway_data.client_secret')<div class="mc-form-error">{{ $message }}</div>@enderror
</div>

<div class="mc-form-group">
    <label class="mc-form-label">{{ trans('paypal::messages.form.environment') }} <span class="mc-form-required">*</span></label>
    <p class="mc-form-help" style="margin-top:0;margin-bottom:var(--space-1);">
        {{ trans('paypal::messages.form.environment_help') }}
    </p>
    @php $env = old('gateway_data.environment', $paymentGateway->getGatewayData('environment') ?: 'sandbox'); @endphp
    <select name="gateway_data[environment]"
            class="mc-form-input mc-form-select @error('gateway_data.environment') mc-form-input-error @enderror"
            required>
        <option value="sandbox" {{ $env === 'sandbox' ? 'selected' : '' }}>Sandbox (developer.paypal.com test app)</option>
        <option value="live"    {{ $env === 'live'    ? 'selected' : '' }}>Live (production app)</option>
    </select>
    @error('gateway_data.environment')<div class="mc-form-error">{{ $message }}</div>@enderror
</div>

<div class="mc-alert mc-alert-info" style="margin-top:var(--space-4);">
    <div class="mc-alert-icon">
        <span class="material-symbols-rounded">info</span>
    </div>
    <div class="mc-alert-content">
        <div class="mc-alert-title">{{ trans('paypal::messages.form.credentials_title') }}</div>
        <div class="mc-alert-text">{!! trans('paypal::messages.form.credentials_desc') !!}</div>
    </div>
</div>

<div class="mc-alert mc-alert-info" style="margin-top:var(--space-3);">
    <div class="mc-alert-icon">
        <span class="material-symbols-rounded">subscriptions</span>
    </div>
    <div class="mc-alert-content">
        <div class="mc-alert-title">{{ trans('paypal::messages.form.subscriptions_title') }}</div>
        <div class="mc-alert-text">{{ trans('paypal::messages.form.subscriptions_desc') }}</div>
    </div>
</div>

<div class="mc-alert mc-alert-info" style="margin-top:var(--space-3);">
    <div class="mc-alert-icon">
        <span class="material-symbols-rounded">sync</span>
    </div>
    <div class="mc-alert-content">
        <div class="mc-alert-title">{{ trans('paypal::messages.form.pull_only_title') }}</div>
        <div class="mc-alert-text">{{ trans('paypal::messages.form.pull_only_desc') }}</div>
    </div>
</div>
