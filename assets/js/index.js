const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;
const { createElement } = window.wp.element;

// Inställningarna kommer från KDP_Blocks_Support::get_payment_method_data() i PHP.
const settings = getSetting("kdp_gateway_data", {});
const title = settings.title || "Krokedil Demo Payments";
const description = settings.description || "";
// Visar beskrivningen under betalsättet, både i kassan och i redigeraren.
const Content = () => createElement("p", null, description);

// Registrerar betalningsmetoden i blockkassan.
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
