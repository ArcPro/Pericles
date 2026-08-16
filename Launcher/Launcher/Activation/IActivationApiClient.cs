namespace Launcher.Activation;

public interface IActivationApiClient
{
    Task<ActivationResponse> RedeemKeyAsync(string key, string accessToken, CancellationToken cancellationToken);
}
