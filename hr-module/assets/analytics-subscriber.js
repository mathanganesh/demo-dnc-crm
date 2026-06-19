// Helper for tracking subscriber-level events in both Mixpanel and Amplitude
// Usage:
//   window.trackSubscriberEvent('Subscriber KYC Viewed', { page: 'subscriber_kyc', action: 'view' });
//
// Assumes:
// - Mixpanel snippet is loaded globally as `window.mixpanel`
// - Amplitude Browser SDK is loaded globally as `window.amplitude`
// - Optional: `window.subscriberContext` injected from Blade with subscriber identifiers

(function (window) {
  'use strict';

  function getBaseContext() {
    try {
      if (window.subscriberContext && typeof window.subscriberContext === 'object') {
        return window.subscriberContext;
      }
    } catch (e) {
      // ignore
    }
    return {};
  }

  function mergeContext(props) {
    var base = getBaseContext();
    var merged = {};
    var key;

    for (key in base) {
      if (Object.prototype.hasOwnProperty.call(base, key)) {
        merged[key] = base[key];
      }
    }

    if (props && typeof props === 'object') {
      for (key in props) {
        if (Object.prototype.hasOwnProperty.call(props, key)) {
          merged[key] = props[key];
        }
      }
    }

    return merged;
  }

  function safeTrackMixpanel(eventName, props) {
    try {
      if (window.mixpanel && typeof window.mixpanel.track === 'function') {
        window.mixpanel.track(eventName, props);
      }
    } catch (e) {
      if (window.console && typeof window.console.debug === 'function') {
        console.debug('Mixpanel track error', e);
      }
    }
  }

  function safeTrackAmplitude(eventName, props) {
    try {
      if (window.amplitude && typeof window.amplitude.getInstance === 'function') {
        var instance = window.amplitude.getInstance();
        if (instance && typeof instance.track === 'function') {
          instance.track(eventName, props);
        }
      }
    } catch (e) {
      if (window.console && typeof window.console.debug === 'function') {
        console.debug('Amplitude track error', e);
      }
    }
  }

  function trackSubscriberEvent(eventName, props) {
    if (!eventName) {
      return;
    }
    var mergedProps = mergeContext(props || {});
    safeTrackMixpanel(eventName, mergedProps);
    safeTrackAmplitude(eventName, mergedProps);
  }

  function trackSubscriberPageView(pageKey, extraProps) {
    var props = extraProps || {};
    props.page = pageKey;
    props.action = props.action || 'view';
    trackSubscriberEvent('Subscriber Page Viewed', props);
  }

  // Expose globals
  window.trackSubscriberEvent = trackSubscriberEvent;
  window.trackSubscriberPageView = trackSubscriberPageView;

})(window);

