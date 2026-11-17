const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;
const { createElement } = window.wp.element;

const settings = getSetting("kdp_gateway_data", {});
const title = settings.title || "Krokedil Demo Payments";
const description = settings.description || "";
const Content = () => createElement("p", null, description);

registerPaymentMethod({
  name: "kdp_gateway",
  label: title,
  ariaLabel: title,
  content: createElement(Content),
  edit: createElement(Content),
  canMakePayment: () => true,
  supports: {
    features: ["products"],
  },
});
